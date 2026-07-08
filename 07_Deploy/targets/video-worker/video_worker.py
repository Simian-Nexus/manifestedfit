"""Manifested Fit video worker.

Polls the MFCE video queue, renders a faceless video for each post that
needs one (branded intro + persona-matched voiceover + Pexels b-roll +
quiet background music + captions + spoken like/subscribe outro over a
brand end card), uploads it to YouTube as unlisted, and reports back so
Jonathan gets the Telegram Approve/Reject buttons. Approved videos are
flipped to public and embedded into the post.

Usage:
  python video_worker.py             # process the whole queue once
  python video_worker.py --watch     # ...then wait for Telegram approval
                                     # and auto publish + embed (one-click)
  python video_worker.py --post 33   # only that post id
  python video_worker.py --keep      # keep work dirs for inspection

Branding assets (optional, built by build_branding.py in branding/):
  intro.mp4 is prepended when present; endcard.png hosts the outro.

Prereqs: ffmpeg on PATH; deps installed in venv/ (see build notes: use
`uv pip install --system-certs --python venv\\Scripts\\python.exe ...`
on this machine - plain pip fails on source builds and TLS).
"""

import argparse
import glob
import json
import os
import random
import re
import shutil
import subprocess
import sys
import tempfile
import time

import requests
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(HERE, "branding")
CLIP_SECONDS = 6          # how much of each b-roll clip to use
WIDTH, HEIGHT, FPS = 1920, 1080, 30

# Bluehost mod_security rejects the default python-requests agent with a 406
UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) MFCE-video-worker"}

STOPWORDS = set(
    "a an the and or of to in on with for at from into over under is are be "
    "as by it its this that then their your our his her they we you i shot "
    "cut show showing scene text overlay screen close up wide slow pan "
    "b-roll broll footage clip clips video open close end card".split()
)

_CHATTERBOX = None  # loaded once, reused across posts


def load_config():
    with open(os.path.join(HERE, "config.json"), encoding="utf-8") as f:
        cfg = json.load(f)
    if "PUT-" in cfg.get("pexels_api_key", ""):
        sys.exit("config.json: pexels_api_key is not set yet.")
    return cfg


def voice_config(cfg, persona):
    voices = cfg.get("voices", {})
    return voices.get(persona) or {"edge": cfg.get("tts_voice", "en-US-JennyNeural")}


def youtube_service(cfg):
    creds = Credentials.from_authorized_user_file(os.path.join(HERE, cfg["token_file"]))
    if not creds.valid:
        creds.refresh(Request())
        with open(os.path.join(HERE, cfg["token_file"]), "w", encoding="utf-8") as f:
            f.write(creds.to_json())
    return build("youtube", "v3", credentials=creds)


def mfce(cfg, method, path, **params):
    params["secret"] = cfg["mfce_cron_secret"]
    r = requests.request(method, cfg["mfce_base_url"] + path, params=params,
                         headers=UA, timeout=60)
    r.raise_for_status()
    return r.json()


def run(cmd, cwd=None):
    p = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(f"{cmd[0]} failed:\n{p.stderr[-2000:]}")
    return p


def media_duration(path):
    p = run(["ffprobe", "-v", "error", "-show_entries", "format=duration",
             "-of", "csv=p=0", path])
    return float(p.stdout.strip())


def sentences(text):
    return [s.strip() for s in re.split(r"(?<=[.!?])\s+", text) if s.strip()]


# ---------------------------------------------------------------- tts

def tts_edge(script, vcfg, workdir, stem):
    out = os.path.join(workdir, f"{stem}.mp3")
    txt = os.path.join(workdir, f"{stem}.txt")
    with open(txt, "w", encoding="utf-8") as f:
        f.write(script)
    run([sys.executable, "-m", "edge_tts", "--voice", vcfg["edge"],
         "--file", txt, "--write-media", out])
    return out


def tts_chatterbox(script, vcfg, workdir, stem):
    global _CHATTERBOX
    import torch
    import torchaudio
    from chatterbox.tts import ChatterboxTTS
    if _CHATTERBOX is None:
        device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"  loading Chatterbox on {device}...")
        _CHATTERBOX = ChatterboxTTS.from_pretrained(device=device)
    ref = vcfg.get("ref_wav")
    if ref and not os.path.isabs(ref):
        ref = os.path.join(HERE, ref)
    # generate sentence-chunks (~300 chars) — long single passes degrade
    chunks, cur = [], ""
    for s in sentences(script):
        if len(cur) + len(s) > 300 and cur:
            chunks.append(cur)
            cur = s
        else:
            cur = (cur + " " + s).strip()
    if cur:
        chunks.append(cur)
    kwargs = {"exaggeration": vcfg.get("exaggeration", 0.5),
              "cfg_weight": vcfg.get("cfg_weight", 0.5)}
    if ref and os.path.exists(ref):
        kwargs["audio_prompt_path"] = ref
    wavs = [_CHATTERBOX.generate(c, **kwargs) for c in chunks]
    out = os.path.join(workdir, f"{stem}.wav")
    torchaudio.save(out, torch.cat(wavs, dim=-1), _CHATTERBOX.sr)
    return out


def tts(script, cfg, persona, workdir, stem="voice"):
    vcfg = voice_config(cfg, persona)
    engine = cfg.get("tts_engine", "edge")
    if engine == "chatterbox":
        try:
            return tts_chatterbox(script, vcfg, workdir, stem)
        except Exception as e:
            print(f"  chatterbox failed ({e}); falling back to edge-tts")
    return tts_edge(script, vcfg, workdir, stem)


# ---------------------------------------------------------------- assets

def pexels_queries(brief, title):
    """Turn the visual_direction prose into a handful of stock-search terms."""
    text = brief.get("visual_direction") or title
    queries = []
    for frag in re.split(r"[.,;\n•-]+", text):
        words = [w.lower() for w in re.findall(r"[A-Za-z]{3,}", frag)
                 if w.lower() not in STOPWORDS]
        if len(words) >= 2:
            queries.append(" ".join(words[:3]))
    return queries[:8] or ["morning workout", "healthy lifestyle"]


def fetch_clips(cfg, queries, workdir, need_seconds):
    """Download landscape HD clips until we can cover the voiceover."""
    clips, have = [], 0.0
    headers = {"Authorization": cfg["pexels_api_key"]}
    for q in queries * 3:  # cycle queries if we run short
        if have >= need_seconds + 1:
            break
        r = requests.get("https://api.pexels.com/videos/search",
                         params={"query": q, "per_page": 3, "orientation": "landscape"},
                         headers=headers, timeout=60)
        r.raise_for_status()
        for v in r.json().get("videos", []):
            files = [f for f in v["video_files"] if f["width"] >= 1280]
            if not files:
                continue
            best = min(files, key=lambda f: abs(f["width"] - WIDTH))
            raw = os.path.join(workdir, f"raw_{len(clips)}.mp4")
            with requests.get(best["link"], stream=True, timeout=120) as dl:
                dl.raise_for_status()
                with open(raw, "wb") as f:
                    shutil.copyfileobj(dl.raw, f)
            clips.append(raw)
            have += min(v.get("duration", CLIP_SECONDS), CLIP_SECONDS)
            break  # one video per query keeps the b-roll varied
    if not clips:
        raise RuntimeError("Pexels returned no usable clips.")
    return clips


def pick_music(cfg, persona):
    """Random track from music/<persona>/ if present, else music/ root."""
    base = os.path.join(HERE, cfg.get("music_dir", "music"))
    pools = []
    if persona:
        pools.append(os.path.join(base, persona))
    pools.append(base)
    for pool in pools:
        tracks = [f for ext in ("*.mp3", "*.wav", "*.m4a")
                  for f in glob.glob(os.path.join(pool, ext))]
        if tracks:
            return random.choice(tracks)
    return None


def build_srt(segments, path):
    """segments: list of (script_text, start_seconds, duration_seconds)."""
    def stamp(x):
        h, rem = divmod(x, 3600); m, s = divmod(rem, 60)
        return f"{int(h):02}:{int(m):02}:{int(s):02},{int((s % 1) * 1000):03}"
    idx = 1
    with open(path, "w", encoding="utf-8") as f:
        for script, start, dur in segments:
            sents = sentences(script)
            words = sum(len(s.split()) for s in sents) or 1
            t = start
            for s in sents:
                d = dur * len(s.split()) / words
                f.write(f"{idx}\n{stamp(t)} --> {stamp(t + d)}\n{s}\n\n")
                idx += 1
                t += d


# ---------------------------------------------------------------- rendering

def render(cfg, brief, title, persona, workdir):
    outros = cfg.get("outros", {})
    outro_text = outros.get(persona) or outros.get("default") or ""
    endcard = os.path.join(BRAND, "endcard.png")
    intro = os.path.join(BRAND, "intro.mp4")

    voice_main = tts(brief["voiceover_script"], cfg, persona, workdir)
    main_dur = media_duration(voice_main)
    outro_dur = 0.0
    if outro_text:
        voice_outro = tts(outro_text, cfg, persona, workdir, stem="outro")
        outro_dur = media_duration(voice_outro)
        voice_all = "voice_all.m4a"
        run(["ffmpeg", "-y", "-i", os.path.basename(voice_main),
             "-i", os.path.basename(voice_outro),
             "-filter_complex", "[0:a][1:a]concat=n=2:v=0:a=1[a]",
             "-map", "[a]", "-c:a", "aac", "-b:a", "192k", voice_all],
            cwd=workdir)
    else:
        voice_all = os.path.basename(voice_main)
    total = main_dur + outro_dur
    print(f"  voiceover: {main_dur:.1f}s + {outro_dur:.1f}s outro")

    clips = fetch_clips(cfg, pexels_queries(brief, title), workdir, main_dur)
    print(f"  b-roll: {len(clips)} clips")

    # normalize every clip to the same format so concat is safe
    norm = []
    for i, c in enumerate(clips):
        out = os.path.join(workdir, f"norm_{i}.mp4")
        run(["ffmpeg", "-y", "-i", c, "-t", str(CLIP_SECONDS),
             "-vf", f"scale={WIDTH}:{HEIGHT}:force_original_aspect_ratio=increase,"
                    f"crop={WIDTH}:{HEIGHT},fps={FPS},setsar=1",
             "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "22", out])
        norm.append(out)

    # the end card fills the outro; b-roll only needs to cover the main script
    use_endcard = outro_dur > 0 and os.path.exists(endcard)
    broll_target = main_dur if use_endcard else total
    if use_endcard:
        run(["ffmpeg", "-y", "-loop", "1", "-t", str(outro_dur + 0.7),
             "-i", endcard,
             "-vf", f"scale={WIDTH}:{HEIGHT},fps={FPS},setsar=1,fade=in:d=0.5",
             "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "22",
             "-pix_fmt", "yuv420p", os.path.join(workdir, "norm_end.mp4")])

    concat_list = os.path.join(workdir, "list.txt")
    with open(concat_list, "w", encoding="utf-8") as f:
        need, i = broll_target + 0.3, 0
        while need > 0:
            clip = norm[i % len(norm)]
            f.write(f"file '{os.path.basename(clip)}'\n")
            need -= CLIP_SECONDS
            i += 1
        if use_endcard:
            f.write("file 'norm_end.mp4'\n")

    srt = os.path.join(workdir, "captions.srt")
    segs = [(brief["voiceover_script"], 0.0, main_dur)]
    if outro_text:
        segs.append((outro_text, main_dur, outro_dur))
    build_srt(segs, srt)

    music = pick_music(cfg, persona)
    print(f"  music: {os.path.basename(music) if music else 'none found'}")

    vf_parts = []
    if cfg.get("burn_captions", True):
        vf_parts.append("subtitles=captions.srt:force_style='FontSize=18,"
                        "Outline=2,MarginV=40,PrimaryColour=&Hffffff&'")
    wm = cfg.get("watermark", "").replace(":", r"\:")
    if wm:
        vf_parts.append(f"drawtext=text='{wm}':fontcolor=white@0.7:fontsize=36:"
                        f"x=w-tw-40:y=40:shadowx=2:shadowy=2")

    body = "body.mp4" if os.path.exists(intro) else "final.mp4"
    cmd = ["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", "list.txt",
           "-i", voice_all]
    if music:
        vol = cfg.get("music_volume", 0.09)
        cmd += ["-stream_loop", "-1", "-i", music,
                "-filter_complex",
                f"[2:a]volume={vol}[m];[1:a][m]amix=inputs=2:duration=first:"
                f"dropout_transition=3,afade=t=out:st={max(total - 2, 0)}:d=2[a]",
                "-map", "0:v", "-map", "[a]"]
    else:
        cmd += ["-map", "0:v", "-map", "1:a"]
    if vf_parts:
        cmd += ["-vf", ",".join(vf_parts)]
    cmd += ["-t", str(total + 0.5),
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "21",
            "-c:a", "aac", "-b:a", "160k", "-ar", "44100", "-ac", "2",
            "-movflags", "+faststart", body]
    # run from workdir so the subtitles filter gets a plain relative filename
    run(cmd, cwd=workdir)

    if os.path.exists(intro):
        run(["ffmpeg", "-y", "-i", intro, "-i", os.path.join(workdir, body),
             "-filter_complex",
             f"[0:v]scale={WIDTH}:{HEIGHT},fps={FPS},setsar=1[v0];"
             f"[0:a]aresample=44100,aformat=channel_layouts=stereo[a0];"
             f"[1:v]null[v1];"
             f"[1:a]aresample=44100,aformat=channel_layouts=stereo[a1];"
             f"[v0][a0][v1][a1]concat=n=2:v=1:a=1[v][a]",
             "-map", "[v]", "-map", "[a]",
             "-c:v", "libx264", "-preset", "veryfast", "-crf", "21",
             "-c:a", "aac", "-b:a", "160k", "-movflags", "+faststart",
             os.path.join(workdir, "final.mp4")])
    return os.path.join(workdir, "final.mp4"), srt


# ---------------------------------------------------------------- youtube

def upload_unlisted(yt, path, brief, permalink):
    desc = (brief.get("youtube_description") or "").replace("{POST_URL}", permalink or "")
    body = {
        "snippet": {"title": (brief.get("youtube_title") or "Manifested Fit")[:100],
                    "description": desc, "categoryId": "26"},
        "status": {"privacyStatus": "unlisted", "selfDeclaredMadeForKids": False},
    }
    media = MediaFileUpload(path, mimetype="video/mp4", resumable=True)
    req = yt.videos().insert(part="snippet,status", body=body, media_body=media)
    resp = None
    while resp is None:
        status, resp = req.next_chunk()
        if status:
            print(f"  upload {int(status.progress() * 100)}%")
    return resp["id"]


def upload_captions(yt, video_id, srt):
    try:
        yt.captions().insert(
            part="snippet",
            body={"snippet": {"videoId": video_id, "language": "en",
                              "name": "English"}},
            media_body=MediaFileUpload(srt, mimetype="application/octet-stream"),
        ).execute()
        print("  caption track uploaded")
    except Exception as e:
        print(f"  caption upload skipped: {e}")


def make_public(yt, video_id):
    yt.videos().update(part="status", body={
        "id": video_id,
        "status": {"privacyStatus": "public", "selfDeclaredMadeForKids": False},
    }).execute()


def youtube_id(url):
    m = re.search(r"(?:v=|youtu\.be/|/shorts/)([\w-]{11})", url or "")
    return m.group(1) if m else None


# ---------------------------------------------------------------- main

def publish_and_embed(cfg, yt, pid, vid):
    make_public(yt, vid)
    mfce(cfg, "POST", "/video-embed", post_id=pid,
         youtube_url=f"https://www.youtube.com/watch?v={vid}")
    print(f"#{pid}: public + embedded.")


def watch_for_approval(cfg, yt, pending, minutes):
    """Poll the queue until every uploaded video is approved (or rejected)."""
    deadline = time.time() + minutes * 60
    print(f"Waiting up to {minutes} min for Telegram approval of: "
          + ", ".join(f"#{p}" for p in pending))
    while pending and time.time() < deadline:
        time.sleep(30)
        for item in mfce(cfg, "GET", "/video-queue"):
            pid = item["post_id"]
            if pid not in pending:
                continue
            if item["video_status"] == "approved":
                vid = youtube_id(item.get("preview_url"))
                if vid:
                    publish_and_embed(cfg, yt, pid, vid)
                pending.discard(pid)
            elif item["video_status"] == "rejected":
                print(f"#{pid}: rejected — will regenerate on the next run.")
                pending.discard(pid)
    for pid in pending:
        print(f"#{pid}: no decision within {minutes} min — run again later.")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--post", type=int, help="only process this post id")
    ap.add_argument("--keep", action="store_true", help="keep work dirs")
    ap.add_argument("--watch", action="store_true",
                    help="wait for Telegram approval, then publish + embed")
    args = ap.parse_args()

    cfg = load_config()
    yt = youtube_service(cfg)
    queue = mfce(cfg, "GET", "/video-queue")
    print(f"Queue: {len(queue)} post(s)")
    uploaded = set()

    for item in queue:
        pid, status = item["post_id"], item["video_status"]
        persona = item.get("persona") or ""
        if args.post and pid != args.post:
            continue

        if status == "approved":
            vid = youtube_id(item.get("preview_url"))
            if not vid:
                print(f"#{pid}: approved but no preview_url with a video id — skipping")
                continue
            print(f"#{pid}: approved -> public + embed")
            publish_and_embed(cfg, yt, pid, vid)

        elif status in ("needed", "rejected"):
            brief = item.get("video_brief")
            if not brief or not brief.get("voiceover_script"):
                print(f"#{pid}: no usable video brief — skipping")
                continue
            print(f"#{pid}: rendering \"{item['title']}\" "
                  f"(persona: {persona or 'unknown'}, status {status})")
            workdir = tempfile.mkdtemp(prefix=f"mfce_video_{pid}_")
            try:
                final, srt = render(cfg, brief, item["title"], persona, workdir)
                vid = upload_unlisted(yt, final, brief, item.get("permalink"))
                if cfg.get("upload_srt", True):
                    upload_captions(yt, vid, srt)
                url = f"https://www.youtube.com/watch?v={vid}"
                mfce(cfg, "POST", "/video-ready", post_id=pid, preview_url=url)
                print(f"#{pid}: uploaded unlisted -> {url} (Telegram sent)")
                uploaded.add(pid)
            finally:
                if args.keep:
                    print(f"  work dir kept: {workdir}")
                else:
                    shutil.rmtree(workdir, ignore_errors=True)
        else:
            print(f"#{pid}: status '{status}' — nothing to do")

    if args.watch and uploaded:
        watch_for_approval(cfg, yt, uploaded,
                           cfg.get("approval_wait_minutes", 45))


if __name__ == "__main__":
    main()
