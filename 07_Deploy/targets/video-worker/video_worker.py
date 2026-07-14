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
import hashlib
import json
import os
import random
import re
import shutil
import subprocess
import sys
import time

import requests
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(HERE, "branding")
MIN_BEAT_SECONDS = 4.5    # narration beats shorter than this merge into the next
ENDCARD_HOLD = 5.0        # extra seconds the end card stays up after the outro
WIDTH, HEIGHT, FPS = 1920, 1080, 30
WORK_ROOT = os.path.join(HERE, "work")

# Bluehost mod_security rejects the default python-requests agent with a 406
UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) MFCE-video-worker"}

STOPWORDS = set(
    "a an the and or of to in on with for at from into over under is are be "
    "as by it its this that then their your our his her they we you i shot "
    "cut show showing scene text overlay screen close up wide slow pan "
    "b-roll broll footage clip clips video open close end card".split()
)

_CHATTERBOX = None  # loaded once, reused across posts
_COMFY_PROC = None


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


def ensure_video_brief(cfg, item):
    """Return the queued brief, or self-heal a rare missing-brief draft by
    reading it through the existing WordPress application-password config and
    caching a generated companion brief in the resumable workspace."""
    brief = item.get("video_brief")
    if brief and brief.get("voiceover_script"):
        return normalize_video_brief(brief)
    pid = int(item["post_id"])
    cache_dir = os.path.join(WORK_ROOT, f"post_{pid}")
    cache_path = os.path.join(cache_dir, "synthesized_brief.json")
    cached = json_read(cache_path)
    if cached and cached.get("voiceover_script"):
        print("  resume: reusing synthesized video brief")
        return normalize_video_brief(cached)
    wp_path = cfg.get("wordpress_config_file") or os.path.join(
        HERE, "..", "wordpress", "config.json")
    wp = json_read(os.path.abspath(wp_path), {})
    if not all(wp.get(k) for k in ("site_url", "username", "app_password")):
        return None
    r = requests.get(f"{wp['site_url'].rstrip('/')}/wp-json/wp/v2/posts/{pid}",
                     params={"context": "edit"},
                     auth=(wp["username"], wp["app_password"]), headers=UA, timeout=60)
    r.raise_for_status()
    post = r.json()
    content = ((post.get("content") or {}).get("raw") or
               (post.get("content") or {}).get("rendered") or "")
    prompt = ("Create a 60-90 second faceless YouTube companion-video brief for "
              "this wellness article. The voiceover must be 150-220 spoken words, "
              "stand alone, avoid medical claims, and match the article. Return JSON "
              "with youtube_title, youtube_description ending in 'Full article: "
              "{POST_URL}', voiceover_script, and visual_direction.\n\nTITLE: "
              + item.get("title", "") + "\n\nARTICLE HTML:\n" + content[:18000])
    brief = gemini_json(cfg, [{"text": prompt}], timeout=120)
    if not brief.get("voiceover_script"):
        return None
    os.makedirs(cache_dir, exist_ok=True)
    json_write(cache_path, brief)
    print("  missing WordPress video brief: synthesized and cached locally")
    return normalize_video_brief(brief)


def normalize_video_brief(brief):
    """Coerce harmless provider shape variations into the worker contract."""
    brief = dict(brief)
    direction = brief.get("visual_direction", "")
    if isinstance(direction, list):
        direction = "; ".join(str(x) for x in direction)
    elif isinstance(direction, dict):
        direction = "; ".join(f"{k}: {v}" for k, v in direction.items())
    brief["visual_direction"] = str(direction or "")
    for key in ("youtube_title", "youtube_description", "voiceover_script"):
        if not isinstance(brief.get(key), str):
            brief[key] = str(brief.get(key) or "")
    return brief


def run(cmd, cwd=None):
    p = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(f"{cmd[0]} failed:\n{p.stderr[-2000:]}")
    return p


def media_duration(path):
    p = run(["ffprobe", "-v", "error", "-show_entries", "format=duration",
             "-of", "csv=p=0", path])
    return float(p.stdout.strip())


def valid_media(path):
    try:
        return os.path.exists(path) and os.path.getsize(path) > 1024 and media_duration(path) > 0.1
    except Exception:
        return False


def json_read(path, default=None):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def json_write(path, data):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)
    os.replace(tmp, path)


def job_fingerprint(item, cfg):
    material = {"post_id": item.get("post_id"), "title": item.get("title"),
                "persona": item.get("persona"), "brief": item.get("video_brief"),
                "tts_engine": cfg.get("tts_engine"), "voices": cfg.get("voices"),
                "outros": cfg.get("outros"), "visuals_engine": cfg.get("visuals_engine"),
                "generated_video_provider": cfg.get("generated_video_provider"),
                "local_wan_steps": cfg.get("local_wan_steps"),
                "local_wan_frames": cfg.get("local_wan_frames"),
                "local_wan_cap": cfg.get("local_wan_max_clips_per_video"),
                "veo_model": cfg.get("veo_model"), "veo_cap": cfg.get("veo_max_clips_per_video"),
                "qa_model": cfg.get("gemini_qa_model")}
    raw = json.dumps(material, sort_keys=True, ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def prepare_workdir(item, cfg, fresh=False):
    os.makedirs(WORK_ROOT, exist_ok=True)
    path = os.path.join(WORK_ROOT, f"post_{int(item['post_id'])}")
    fingerprint = job_fingerprint(item, cfg)
    manifest_path = os.path.join(path, "manifest.json")
    old = json_read(manifest_path, {}) if os.path.isdir(path) else {}
    if fresh or (old and old.get("fingerprint") != fingerprint):
        shutil.rmtree(path, ignore_errors=True)
        old = {}
    os.makedirs(path, exist_ok=True)
    manifest = {**old, "post_id": int(item["post_id"]), "fingerprint": fingerprint,
                "updated": time.strftime("%Y-%m-%dT%H:%M:%S")}
    json_write(manifest_path, manifest)
    return path, manifest


def sentences(text):
    return [s.strip() for s in re.split(r"(?<=[.!?])\s+", text) if s.strip()]


# ---------------------------------------------------------------- tts

def tts_edge(script, vcfg, workdir, stem):
    """Synthesize with edge-tts, capturing boundary events so captions and
    b-roll cuts use the REAL spoken timing instead of estimates. Returns
    (mp3_path, timing) where timing is {"sentences": [(text, start_s, end_s)],
    "words": [(start_s, dur_s)]} (either may be empty) or None."""
    out = os.path.join(workdir, f"{stem}.mp3")
    timing_path = os.path.join(workdir, f"{stem}.timing.json")
    if valid_media(out) and os.path.exists(timing_path):
        print(f"  resume: reusing {stem} voiceover")
        return out, json_read(timing_path)
    try:
        import asyncio
        import edge_tts

        sents, words = [], []

        async def _gen():
            com = edge_tts.Communicate(script, vcfg["edge"])
            with open(out, "wb") as f:
                async for ch in com.stream():
                    if ch["type"] == "audio":
                        f.write(ch["data"])
                    elif ch["type"] == "SentenceBoundary":
                        # offsets are in 100-nanosecond ticks
                        sents.append(((ch.get("text") or "").strip(),
                                      ch["offset"] / 1e7,
                                      (ch["offset"] + ch["duration"]) / 1e7))
                    elif ch["type"] == "WordBoundary":
                        words.append((ch["offset"] / 1e7, ch["duration"] / 1e7))

        asyncio.run(_gen())
        timing = {"sentences": sents, "words": words} if (sents or words) else None
        json_write(timing_path, timing)
        return out, timing
    except Exception as e:
        print(f"  edge-tts streaming failed ({e}); falling back to the CLI")
        txt = os.path.join(workdir, f"{stem}.txt")
        with open(txt, "w", encoding="utf-8") as f:
            f.write(script)
        run([sys.executable, "-m", "edge_tts", "--voice", vcfg["edge"],
             "--file", txt, "--write-media", out])
        json_write(timing_path, None)
        return out, None


def tts_chatterbox(script, vcfg, workdir, stem):
    global _CHATTERBOX
    out = os.path.join(workdir, f"{stem}.wav")
    if valid_media(out):
        print(f"  resume: reusing {stem} voiceover")
        return out
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
    torchaudio.save(out, torch.cat(wavs, dim=-1), _CHATTERBOX.sr)
    return out


def tts(script, cfg, persona, workdir, stem="voice"):
    """Returns (audio_path, word_timings_or_None)."""
    vcfg = voice_config(cfg, persona)
    engine = cfg.get("tts_engine", "edge")
    if engine == "chatterbox":
        try:
            return tts_chatterbox(script, vcfg, workdir, stem), None
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


def sentence_times(script, total_dur, timing=None):
    """[(sentence, start_s, end_s), ...] for one voice track.
    Preferred source: edge-tts SentenceBoundary events - the engine's own
    sentence segmentation with exact spoken times. Next best: WordBoundary
    events mapped onto our sentence split. Fallback (chatterbox/CLI): the
    old word-share estimate. Sentence ends are stretched to the next
    sentence's start so captions hold through pauses."""
    if timing and timing.get("sentences"):
        sb = timing["sentences"]
        out, prev = [], 0.0
        for k, (text, st, en) in enumerate(sb):
            st = max(st, prev)
            nxt = sb[k + 1][1] if k + 1 < len(sb) else total_dur
            end = min(max(en, st + 0.2, nxt), total_dur)
            out.append((text, st, end))
            prev = end
        text, st, _ = out[-1]
        out[-1] = (text, st, total_dur)
        return out

    sents = sentences(script)
    counts = [len(s.split()) for s in sents]
    total_words = sum(counts) or 1
    times = []
    words = timing.get("words") if timing else None
    if words and len(words) >= len(sents):
        n, cum, prev = len(words), 0, 0.0
        for s, c in zip(sents, counts):
            cum += c
            idx = min(n - 1, max(0, round(n * cum / total_words) - 1))
            end = max(words[idx][0] + words[idx][1], prev + 0.3)
            times.append((s, prev, min(end, total_dur)))
            prev = times[-1][2]
        s, st, _ = times[-1]
        times[-1] = (s, st, total_dur)  # last sentence runs to the audio end
    else:
        t = 0.0
        for s, c in zip(sents, counts):
            d = total_dur * c / total_words
            times.append((s, t, t + d))
            t += d
    return times


def script_beats(script, main_dur, timing=None):
    """Split the narration into beats and time each one so the b-roll tracks
    what is being said, using real spoken timings when available (the
    captions are built from the same sentence times, so visuals and captions
    change together). Returns [(beat_text, search_query, duration_seconds), ...]."""
    beats, cur, start = [], [], 0.0
    for s, _b, e in sentence_times(script, main_dur, timing):
        cur.append(s)
        if e - start >= MIN_BEAT_SECONDS:
            beats.append((" ".join(cur), e - start))
            cur, start = [], e
    if cur:
        rem = main_dur - start
        if beats and rem < MIN_BEAT_SECONDS / 2:
            text, dur = beats[-1]
            beats[-1] = (text + " " + " ".join(cur), dur + rem)
        else:
            beats.append((" ".join(cur), rem))
    out = []
    for text, dur in beats:
        keywords = [w.lower() for w in re.findall(r"[A-Za-z]{3,}", text)
                    if w.lower() not in STOPWORDS]
        query = " ".join(keywords[:3]) if len(keywords) >= 2 else ""
        out.append((text, query, dur))
    return out


def fetch_beat_clips(cfg, beats, fallback_queries, workdir, cache_keys=None):
    """One landscape HD clip per narration beat (searched from that beat's own
    words). With a Gemini key and visual_qa on, an AI vision check scores
    each candidate's preview frame against the narration line and takes the
    first good fit. Falls back to visual_direction queries, then to reusing
    an earlier clip, so the list always matches the beats one-to-one."""
    headers = {"Authorization": cfg["pexels_api_key"]}
    qa = bool(cfg.get("gemini_api_key")) and bool(cfg.get("visual_qa", 1))
    clips, used_ids = [], set()

    def candidates(query):
        r = requests.get("https://api.pexels.com/videos/search",
                         params={"query": query, "per_page": 6, "orientation": "landscape"},
                         headers=headers, timeout=60)
        r.raise_for_status()
        out = []
        for v in r.json().get("videos", []):
            if v["id"] in used_ids:
                continue
            files = [f for f in v["video_files"] if f["width"] >= 1280]
            if files:
                out.append({"id": v["id"], "dur": v.get("duration", 0),
                            "link": min(files, key=lambda f: abs(f["width"] - WIDTH))["link"],
                            "image": v.get("image")})
        return out

    def download(cand, name):
        raw = os.path.join(workdir, name)
        with requests.get(cand["link"], stream=True, timeout=120) as dl:
            dl.raise_for_status()
            with open(raw, "wb") as f:
                shutil.copyfileobj(dl.raw, f)
        used_ids.add(cand["id"])
        return raw

    for i, (text, query, dur) in enumerate(beats):
        key = cache_keys[i] if cache_keys else f"stock_{i}"
        cached = os.path.join(workdir, f"{key}.mp4")
        if valid_media(cached):
            print(f"  resume: reusing stock beat {key}")
            clips.append(cached)
            continue
        tries = ([query] if query else []) + [fallback_queries[i % len(fallback_queries)]]
        chosen, best, best_score = None, None, -1.0
        for q in tries:
            try:
                cands = candidates(q)
            except requests.RequestException:
                continue
            cands = ([c for c in cands if c["dur"] >= dur] or cands)[:4]
            if not cands:
                continue
            if not (qa and text):
                chosen = cands[0]
                break
            for c in cands:
                try:
                    img = requests.get(c["image"], timeout=30, headers=UA).content
                    score, fit = frame_matches(cfg, img, text)
                except Exception:
                    score, fit = 5.0, True  # QA hiccup: don't block the render
                if score > best_score:
                    best, best_score = c, score
                if fit and score >= 6:
                    chosen = c
                    break
            if chosen:
                break
        chosen = chosen or best  # nothing scored well: take the least-bad one
        if not chosen:
            if not clips:
                raise RuntimeError("Pexels returned no usable clips.")
            clips.append(clips[-1])  # reuse the previous beat's clip
            continue
        clips.append(download(chosen, f"{key}.mp4"))
    return clips


# -------------------------------------------------- gemini planning & QA

GEMINI_BASE = "https://generativelanguage.googleapis.com/v1beta"


def gemini_json(cfg, parts, timeout=90):
    """generateContent call returning parsed JSON (responseMimeType json)."""
    model = cfg.get("gemini_qa_model") or "gemini-3.5-flash"
    r = requests.post(f"{GEMINI_BASE}/models/{model}:generateContent",
                      headers={"x-goog-api-key": cfg["gemini_api_key"],
                               "Content-Type": "application/json"},
                      json={"contents": [{"parts": parts}],
                            "generationConfig": {"responseMimeType": "application/json"}},
                      timeout=timeout)
    r.raise_for_status()
    txt = r.json()["candidates"][0]["content"]["parts"][0]["text"]
    return json.loads(txt)


def preflight_visual_models(cfg):
    """Fail fast before an expensive render when configured AI models are not
    available to this API key. Returns True only when planner/QA and Veo are
    both ready; callers can then make one explicit stock-only fallback."""
    engine = cfg.get("visuals_engine") or "pexels"
    if engine not in ("hybrid", "veo"):
        return True
    try:
        gemini_json(cfg, [{"text": 'Reply exactly as JSON: {"ok": true}'}], timeout=30)
    except Exception as e:
        print(f"  AI visual preflight: planner/QA unavailable ({e})")
        return False
    provider = cfg.get("generated_video_provider") or "veo"
    if provider == "local_wan":
        root = os.path.abspath(os.path.join(HERE, cfg.get(
            "comfyui_dir", "local-video/ComfyUI")))
        required = [
            os.path.join(root, ".venv", "Scripts", "python.exe"),
            os.path.join(root, "models", "diffusion_models", "wan2.1_t2v_1.3B_bf16.safetensors"),
            os.path.join(root, "models", "text_encoders", "umt5_xxl_fp8_e4m3fn_scaled.safetensors"),
            os.path.join(root, "models", "vae", "wan_2.1_vae.safetensors"),
        ]
        missing = [p for p in required if not os.path.exists(p)]
        if missing:
            print(f"  AI visual preflight: local Wan asset missing ({missing[0]})")
            return False
        print("  AI visual preflight: planner/QA + local Wan assets ready")
        return True
    try:
        key = cfg["gemini_api_key"]
        r = requests.get(f"{GEMINI_BASE}/models", headers={"x-goog-api-key": key},
                         params={"pageSize": 1000}, timeout=30)
        r.raise_for_status()
        wanted = "models/" + (cfg.get("veo_model") or "veo-3.1-fast-generate-preview")
        methods = {m.get("name"): m.get("supportedGenerationMethods", [])
                   for m in r.json().get("models", [])}
        if "predictLongRunning" not in methods.get(wanted, []):
            print(f"  AI visual preflight: Veo model unavailable ({wanted})")
            return False
    except Exception as e:
        print(f"  AI visual preflight: could not verify Veo ({e})")
        return False
    print("  AI visual preflight: planner/QA ready; Veo model visible (quota checked on submit)")
    return True


def frame_matches(cfg, jpeg_bytes, beat_text):
    """AI vision check: does this frame fit the narration line spoken at this
    timestamp? Returns (score_0_to_10, fit_bool)."""
    import base64
    prompt = ("You are quality-checking b-roll for a calm wellness video. "
              "The narration spoken over this exact moment is:\n\""
              + beat_text + "\"\n\nDoes the attached frame visually fit that "
              "line (subject, mood, setting)? Judge strictly: a generic gym "
              "shot under a line about sleep is a bad fit. "
              'Reply as JSON: {"score": 0-10, "fit": true|false}.')
    data = gemini_json(cfg, [
        {"text": prompt},
        {"inlineData": {"mimeType": "image/jpeg",
                        "data": base64.b64encode(jpeg_bytes).decode()}},
    ])
    return float(data.get("score", 0)), bool(data.get("fit"))


def veo_clip_ok(cfg, clip, beat_text):
    """Run the same frame check on a generated Veo clip's middle frame."""
    jpg = clip + ".qa.jpg"
    try:
        mid = media_duration(clip) / 2
        run(["ffmpeg", "-y", "-ss", f"{mid:.2f}", "-i", clip,
             "-frames:v", "1", "-q:v", "3", jpg])
        with open(jpg, "rb") as f:
            score, fit = frame_matches(cfg, f.read(), beat_text)
        return fit or score >= 5
    except Exception:
        return True  # QA hiccup: keep the clip we paid for


def plan_beats(cfg, beats, brief, cap):
    """Hybrid planner: ask Gemini which beats deserve generated (Veo) footage
    and which are classic stock material, plus a tailored prompt/query for
    each. Returns [{"source","veo_prompt","pexels_query"}, ...] per beat."""
    listing = "\n".join(f'{i + 1}. ({d:.1f}s) "{t}"'
                        for i, (t, _q, d) in enumerate(beats))
    prompt = (
        "You are planning visuals for a faceless wellness YouTube video. The "
        "narration is split into timed beats; each beat gets exactly one clip, "
        "either GENERATED by an AI video model (Veo - best for specific, "
        "imaginative, metaphorical, or hard-to-find-in-stock moments) or found "
        "on Pexels STOCK (best for generic scenes: parks, kitchens, yoga mats, "
        f"sunrises, people walking). At most {cap} beats may use Veo - spend "
        "them only where generated footage clearly beats stock.\n\nBeats:\n"
        + listing
        + "\n\nOverall visual direction for the video: "
        + (brief.get("visual_direction") or "n/a")
        + '\n\nReply as JSON: {"beats": [{"n": 1, "source": "veo" or "pexels", '
        '"veo_prompt": "detailed cinematic shot prompt (veo beats only, else empty)", '
        '"pexels_query": "2-3 word stock search (always provide, used as fallback)"}]}'
        " - exactly one entry per beat, in order."
    )
    data = gemini_json(cfg, [{"text": prompt}])
    entries = data.get("beats") or []
    if len(entries) != len(beats):
        raise RuntimeError(f"planner returned {len(entries)} entries for {len(beats)} beats")
    specs, veo_n = [], 0
    for e in entries:
        src = "veo" if (e.get("source") == "veo" and veo_n < cap) else "pexels"
        if src == "veo":
            veo_n += 1
        specs.append({"source": src,
                      "veo_prompt": (e.get("veo_prompt") or "").strip(),
                      "pexels_query": (e.get("pexels_query") or "").strip()})
    return specs


# ------------------------------------------------------------ veo visuals


def veo_prompt(beat_text, brief):
    style = (brief.get("visual_direction") or "").strip()[:300]
    p = ("Cinematic b-roll shot for a calm wellness brand video. Photorealistic, "
         "soft natural light, gentle camera movement, no on-screen text, no "
         "logos, no people talking to camera. Visualize this moment from the "
         "narration: " + beat_text)
    if style:
        p += " Overall visual direction for the video: " + style
    return p


def veo_generate_clip(cfg, prompt, seconds, workdir, stem):
    """Generate one clip with Google Veo via the Gemini API (long-running
    operation: submit, poll, download). Raises on any failure so the caller
    can fall back to Pexels for that beat."""
    key = cfg["gemini_api_key"]
    model = cfg.get("veo_model") or "veo-3.1-fast-generate-preview"
    headers = {"x-goog-api-key": key, "Content-Type": "application/json"}
    body = {"instances": [{"prompt": prompt}],
            "parameters": {"aspectRatio": "16:9"}}
    if "3.1" in model:  # Veo 3.1 accepts 4/6/8s; Veo 3.0 is fixed at 8s
        body["parameters"]["durationSeconds"] = next(
            (d for d in (4, 6, 8) if d + 0.2 >= seconds), 8)
    out = os.path.join(workdir, f"{stem}.mp4")
    operation_path = os.path.join(workdir, f"{stem}.operation.json")
    if valid_media(out):
        print(f"  resume: reusing completed {stem}")
        return out
    saved = json_read(operation_path, {})
    op = saved.get("name") if saved.get("model") == model else None
    if op:
        print(f"  resume: polling existing Veo job for {stem}")
    else:
        r = requests.post(f"{GEMINI_BASE}/models/{model}:predictLongRunning",
                          headers=headers, json=body, timeout=60)
        r.raise_for_status()
        op = r.json()["name"]
        json_write(operation_path, {"name": op, "model": model, "prompt": prompt})
    deadline = time.time() + float(cfg.get("veo_timeout_minutes") or 6) * 60
    while time.time() < deadline:
        time.sleep(10)
        r = requests.get(f"{GEMINI_BASE}/{op}", headers=headers, timeout=60)
        r.raise_for_status()
        data = r.json()
        if not data.get("done"):
            continue
        if "error" in data:
            if os.path.exists(operation_path):
                os.remove(operation_path)  # terminal job failure; a rerun may resubmit
            raise RuntimeError(f"Veo: {data['error'].get('message', data['error'])}")
        resp = data.get("response", {})
        # the response shape has varied across Veo releases; check both
        gv = resp.get("generateVideoResponse") or resp
        samples = gv.get("generatedSamples") or gv.get("generatedVideos") or []
        video = (samples[0].get("video") or {}) if samples else {}
        uri = video.get("uri") or video.get("videoUri")
        if not uri:
            raise RuntimeError(f"Veo: no video in response: {str(resp)[:300]}")
        with requests.get(uri, headers={"x-goog-api-key": key}, stream=True,
                          timeout=300) as dl:
            dl.raise_for_status()
            with open(out, "wb") as f:
                shutil.copyfileobj(dl.raw, f)
        if os.path.exists(operation_path):
            os.remove(operation_path)
        return out
    raise RuntimeError("Veo: generation timed out")


def ensure_comfyui(cfg):
    """Return the local ComfyUI URL, starting its private server if needed."""
    global _COMFY_PROC
    base = cfg.get("comfyui_url") or "http://127.0.0.1:8188"
    try:
        requests.get(base + "/system_stats", timeout=3).raise_for_status()
        return base
    except requests.RequestException:
        pass
    root = os.path.abspath(os.path.join(HERE, cfg.get("comfyui_dir", "local-video/ComfyUI")))
    python = os.path.join(root, ".venv", "Scripts", "python.exe")
    log = open(os.path.join(root, "comfyui_worker.log"), "a", encoding="utf-8")
    _COMFY_PROC = subprocess.Popen(
        [python, "-u", "main.py", "--listen", "127.0.0.1", "--port", "8188",
         "--lowvram", "--disable-api-nodes", "--disable-auto-launch"],
        cwd=root, stdout=log, stderr=subprocess.STDOUT, text=True)
    deadline = time.time() + 180
    while time.time() < deadline:
        if _COMFY_PROC.poll() is not None:
            raise RuntimeError("Local ComfyUI exited during startup; see comfyui_worker.log")
        try:
            requests.get(base + "/system_stats", timeout=3).raise_for_status()
            return base
        except requests.RequestException:
            time.sleep(3)
    raise RuntimeError("Local ComfyUI did not start within 3 minutes")


def local_wan_generate_clip(cfg, prompt, seconds, workdir, stem):
    """Generate a local Wan 2.1 clip and slow it to the narration beat."""
    out = os.path.join(workdir, f"{stem}.mp4")
    if valid_media(out):
        print(f"  resume: reusing completed {stem}")
        return out
    base = ensure_comfyui(cfg)
    steps = int(cfg.get("local_wan_steps") or 20)
    frames = int(cfg.get("local_wan_frames") or 49)
    seed = int(hashlib.sha256(prompt.encode("utf-8")).hexdigest()[:15], 16)
    negative = ("blurry, static image, low quality, text, watermark, logo, "
                "distorted, deformed, oversaturated, jitter, duplicate objects")
    workflow = {
        "1": {"class_type": "UNETLoader", "inputs": {"unet_name": "wan2.1_t2v_1.3B_bf16.safetensors", "weight_dtype": "default"}},
        "2": {"class_type": "CLIPLoader", "inputs": {"clip_name": "umt5_xxl_fp8_e4m3fn_scaled.safetensors", "type": "wan", "device": "cpu"}},
        "3": {"class_type": "VAELoader", "inputs": {"vae_name": "wan_2.1_vae.safetensors"}},
        "4": {"class_type": "CLIPTextEncode", "inputs": {"text": prompt, "clip": ["2", 0]}},
        "5": {"class_type": "CLIPTextEncode", "inputs": {"text": negative, "clip": ["2", 0]}},
        "6": {"class_type": "ModelSamplingSD3", "inputs": {"model": ["1", 0], "shift": 8.0}},
        "7": {"class_type": "EmptyHunyuanLatentVideo", "inputs": {"width": 832, "height": 480, "length": frames, "batch_size": 1}},
        "8": {"class_type": "KSampler", "inputs": {"model": ["6", 0], "seed": seed,
            "steps": steps, "cfg": 6.0, "sampler_name": "uni_pc", "scheduler": "simple",
            "positive": ["4", 0], "negative": ["5", 0], "latent_image": ["7", 0], "denoise": 1.0}},
        "9": {"class_type": "VAEDecode", "inputs": {"samples": ["8", 0], "vae": ["3", 0]}},
        "10": {"class_type": "CreateVideo", "inputs": {"images": ["9", 0], "fps": 16.0}},
        "11": {"class_type": "SaveVideo", "inputs": {"video": ["10", 0],
            "filename_prefix": f"mf_worker/{stem}", "format": "mp4", "codec": "h264"}},
    }
    r = requests.post(base + "/prompt", json={"prompt": workflow}, timeout=30)
    r.raise_for_status()
    submitted = r.json()
    if submitted.get("node_errors"):
        raise RuntimeError(f"Local Wan workflow rejected: {submitted['node_errors']}")
    prompt_id = submitted["prompt_id"]
    print(f"  local Wan: {steps} steps, {frames} frames (job {prompt_id[:8]})")
    deadline = time.time() + float(cfg.get("local_wan_timeout_minutes") or 12) * 60
    result = None
    while time.time() < deadline:
        history = requests.get(base + f"/history/{prompt_id}", timeout=30).json()
        if prompt_id in history:
            images = history[prompt_id].get("outputs", {}).get("11", {}).get("images", [])
            if not images:
                raise RuntimeError("Local Wan completed without a saved video")
            result = images[0]
            break
        time.sleep(5)
    if not result:
        raise RuntimeError("Local Wan generation timed out")
    root = os.path.abspath(os.path.join(HERE, cfg.get("comfyui_dir", "local-video/ComfyUI")))
    raw = os.path.join(root, "output", result.get("subfolder", ""), result["filename"])
    raw_dur = media_duration(raw)
    run(["ffmpeg", "-y", "-i", raw, "-vf", f"setpts={seconds / raw_dur:.6f}*PTS",
         "-an", "-c:v", "libx264", "-crf", "20", "-pix_fmt", "yuv420p", out])
    return out


def gather_beat_clips(cfg, beats, brief, title, workdir):
    """One clip per narration beat.
    - 'pexels': stock only (AI frame-check per candidate when QA is on).
    - 'veo': generate every beat up to veo_max_clips_per_video, rest stock.
    - 'hybrid': Gemini plans which beats deserve generated footage and which
      are stock material, with a tailored prompt/query per beat.
    Every generated clip is frame-checked against its narration line (QA);
    any Veo failure or QA rejection falls back to stock for that beat."""
    fallback = pexels_queries(brief, title)
    has_gem = bool(cfg.get("gemini_api_key"))
    engine = cfg.get("visuals_engine") or "pexels"
    if engine in ("veo", "hybrid") and not cfg.get("_ai_visuals_ready", True):
        print(f"  visuals result: {engine} requested -> Pexels fallback (preflight failed)")
        return fetch_beat_clips(cfg, beats, fallback, workdir)
    if engine in ("veo", "hybrid") and not has_gem:
        print("  visuals: gemini_api_key missing -> pexels only")
        engine = "pexels"
    if engine == "pexels":
        return fetch_beat_clips(cfg, beats, fallback, workdir)

    provider = cfg.get("generated_video_provider") or "veo"
    cap = int((cfg.get("local_wan_max_clips_per_video") or 2) if provider == "local_wan"
              else (cfg.get("veo_max_clips_per_video") or 8))
    qa = has_gem and bool(cfg.get("visual_qa", 1))
    specs = None
    if engine == "hybrid":
        plan_path = os.path.join(workdir, "hybrid_plan.json")
        try:
            specs = json_read(plan_path)
            if specs and len(specs) == len(beats):
                print("  resume: reusing hybrid beat plan")
            else:
                specs = plan_beats(cfg, beats, brief, cap)
                json_write(plan_path, specs)
            n_veo = sum(1 for s in specs if s["source"] == "veo")
            print(f"  hybrid plan: {n_veo} generated ({provider}) + {len(specs) - n_veo} Pexels beats")
        except Exception as e:
            print(f"  hybrid planner failed ({e}); Veo for the first {cap} beats")
    if specs is None:
        specs = [{"source": "veo" if i < cap else "pexels",
                  "veo_prompt": "", "pexels_query": ""}
                 for i in range(len(beats))]

    clips = [None] * len(beats)
    provenance = [{"beat": i + 1, "narration": b[0],
                   "requested": specs[i]["source"], "actual": None,
                   "reason": None} for i, b in enumerate(beats)]
    veo_used = 0
    veo_quota_blocked = False
    stock_beats = []  # (original index, beat with the planner's query)
    for i, ((text, query, dur), spec) in enumerate(zip(beats, specs)):
        if spec["source"] == "veo" and veo_quota_blocked:
            provenance[i]["reason"] = "Veo quota unavailable after earlier 429; used stock fallback"
        elif spec["source"] == "veo" and veo_used < cap:
            try:
                print(f"  {provider}: beat {i + 1}/{len(beats)} ({dur:.1f}s)...")
                generator = local_wan_generate_clip if provider == "local_wan" else veo_generate_clip
                stem = f"local_wan_{i}" if provider == "local_wan" else f"veo_{i}"
                clip = generator(cfg, spec["veo_prompt"] or veo_prompt(text, brief),
                                 dur, workdir, stem)
                veo_used += 1
                if qa and not veo_clip_ok(cfg, clip, text):
                    print(f"  veo beat {i + 1}: frame check failed -> stock instead")
                    provenance[i]["reason"] = "Veo frame QA rejected; used stock fallback"
                    clip = None
                clips[i] = clip
                if clip:
                    provenance[i]["actual"] = provider
            except Exception as e:
                print(f"  veo beat {i + 1} failed ({e}) -> stock instead")
                provenance[i]["reason"] = f"Veo failed; used stock fallback: {e}"
                if provider == "veo" and isinstance(e, requests.HTTPError) and e.response is not None and e.response.status_code == 429:
                    veo_quota_blocked = True
                    print("  veo quota unavailable: remaining generated beats will use stock")
        if clips[i] is None:
            stock_beats.append((i, (text, spec["pexels_query"] or query, dur)))
    if stock_beats:
        filled = fetch_beat_clips(cfg, [b for _, b in stock_beats], fallback, workdir,
                                  [f"stock_{i}" for i, _ in stock_beats])
        for (i, _b), c in zip(stock_beats, filled):
            clips[i] = c
            provenance[i]["actual"] = "pexels"
            if not provenance[i]["reason"]:
                provenance[i]["reason"] = ("Hybrid selected stock" if specs[i]["source"] == "pexels"
                                             else "Stock fallback")
    json_write(os.path.join(workdir, "visual_provenance.json"), provenance)
    actual_generated = sum(1 for p in provenance if p["actual"] in ("veo", "local_wan"))
    print(f"  visual provenance: {actual_generated} {provider} + {len(provenance) - actual_generated} Pexels in final")
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


def build_srt(entries, path):
    """entries: list of (sentence_text, start_seconds, end_seconds)."""
    def stamp(x):
        h, rem = divmod(x, 3600); m, s = divmod(rem, 60)
        return f"{int(h):02}:{int(m):02}:{int(s):02},{int((s % 1) * 1000):03}"
    with open(path, "w", encoding="utf-8") as f:
        for idx, (text, start, end) in enumerate(entries, 1):
            f.write(f"{idx}\n{stamp(start)} --> {stamp(end)}\n{text}\n\n")


# ---------------------------------------------------------------- rendering

def render(cfg, brief, title, persona, workdir):
    cached_final = os.path.join(workdir, "final.mp4")
    cached_srt = os.path.join(workdir, "captions.srt")
    if valid_media(cached_final) and os.path.exists(cached_srt):
        print("  resume: reusing completed final render")
        return cached_final, cached_srt
    outros = cfg.get("outros", {})
    outro_text = outros.get(persona) or outros.get("default") or ""
    endcard_mp4 = os.path.join(BRAND, "endcard.mp4")
    endcard_png = os.path.join(BRAND, "endcard.png")
    ding = os.path.join(BRAND, "ding.mp3")
    intro = os.path.join(BRAND, "intro.mp4")

    voice_main, timing_main = tts(brief["voiceover_script"], cfg, persona, workdir)
    main_dur = media_duration(voice_main)
    outro_dur, timing_outro = 0.0, None
    if outro_text:
        voice_outro, timing_outro = tts(outro_text, cfg, persona, workdir, stem="outro")
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

    # The end card fills the outro (plus a hold so it doesn't cut away too
    # fast); the b-roll beats only need to cover the main script.
    hold = float(cfg.get("endcard_hold_seconds", ENDCARD_HOLD))
    use_endcard = outro_dur > 0 and (os.path.exists(endcard_mp4) or os.path.exists(endcard_png))

    # One clip per narration beat, cut to the beat's real speech time (word
    # boundaries from edge-tts), so the visuals follow what is being said.
    beats = script_beats(brief["voiceover_script"], main_dur, timing_main)
    if not use_endcard and main_dur > 0:
        scale = total / main_dur
        beats = [(t, q, d * scale) for t, q, d in beats]
    requested_engine = cfg.get("visuals_engine") or "pexels"
    cfg["_ai_visuals_ready"] = preflight_visual_models(cfg)
    print(f"  visuals requested: {requested_engine}, {len(beats)} narration beats")
    clips = gather_beat_clips(cfg, beats, brief, title, workdir)
    print(f"  b-roll: {len(clips)} beat-aligned clips")

    # normalize every clip to the same format so concat is safe; tpad clones
    # the last frame when a stock clip is shorter than its beat
    norm = []
    for i, (c, (_t, _q, dur)) in enumerate(zip(clips, beats)):
        out = os.path.join(workdir, f"norm_{i}.mp4")
        run(["ffmpeg", "-y", "-i", c,
             "-vf", f"scale={WIDTH}:{HEIGHT}:force_original_aspect_ratio=increase,"
                    f"crop={WIDTH}:{HEIGHT},fps={FPS},setsar=1,"
                    f"tpad=stop_mode=clone:stop_duration={dur:.3f}",
             "-t", f"{dur:.3f}",
             "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "22", out])
        norm.append(out)

    end_len = 0.0
    if use_endcard:
        end_len = outro_dur + hold + 0.7
        if os.path.exists(endcard_mp4):
            # animated card (bell ding-ding + subscribe click); hold its last
            # frame for however long the outro + hold needs
            run(["ffmpeg", "-y", "-i", endcard_mp4,
                 "-vf", f"scale={WIDTH}:{HEIGHT},fps={FPS},setsar=1,"
                        f"tpad=stop_mode=clone:stop_duration={end_len:.3f}",
                 "-t", f"{end_len:.3f}",
                 "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "22",
                 "-pix_fmt", "yuv420p", os.path.join(workdir, "norm_end.mp4")])
        else:
            run(["ffmpeg", "-y", "-loop", "1", "-t", f"{end_len:.3f}",
                 "-i", endcard_png,
                 "-vf", f"scale={WIDTH}:{HEIGHT},fps={FPS},setsar=1,fade=in:d=0.5",
                 "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "22",
                 "-pix_fmt", "yuv420p", os.path.join(workdir, "norm_end.mp4")])

    concat_list = os.path.join(workdir, "list.txt")
    with open(concat_list, "w", encoding="utf-8") as f:
        for clip in norm:
            f.write(f"file '{os.path.basename(clip)}'\n")
        if use_endcard:
            f.write("file 'norm_end.mp4'\n")

    srt = os.path.join(workdir, "captions.srt")
    entries = sentence_times(brief["voiceover_script"], main_dur, timing_main)
    if outro_text:
        entries += [(s, main_dur + b, main_dur + e)
                    for s, b, e in sentence_times(outro_text, outro_dur, timing_outro)]
    build_srt(entries, srt)

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

    # video runs past the voice while the end card holds; audio is padded to
    # match, and the bell ding lands 2.0s into the animated card
    tail = (end_len - outro_dur) if use_endcard else 0.5
    vid_total = total + tail
    use_ding = use_endcard and os.path.exists(endcard_mp4) and os.path.exists(ding)

    body = "body.mp4" if os.path.exists(intro) else "final.mp4"
    cmd = ["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", "list.txt",
           "-i", voice_all]
    idx = 2
    music_idx = ding_idx = None
    if music:
        cmd += ["-stream_loop", "-1", "-i", music]
        music_idx = idx
        idx += 1
    if use_ding:
        cmd += ["-i", ding]
        ding_idx = idx
        idx += 1

    ding_ms = int((main_dur + 2.0) * 1000)
    af = []
    if use_ding:
        af.append(f"[{ding_idx}:a]adelay={ding_ms}|{ding_ms},volume=0.5[dg]")
        af.append(f"[1:a]apad=pad_dur={tail + 1:.2f}[vp0]")
        af.append("[vp0][dg]amix=inputs=2:normalize=0[vp]")
    else:
        af.append(f"[1:a]apad=pad_dur={tail + 1:.2f}[vp]")
    if music:
        vol = cfg.get("music_volume", 0.09)
        af.append(f"[{music_idx}:a]volume={vol}[m]")
        af.append(f"[vp][m]amix=inputs=2:duration=first:dropout_transition=3,"
                  f"afade=t=out:st={max(vid_total - 2.5, 0):.2f}:d=2.5[a]")
    else:
        af.append(f"[vp]afade=t=out:st={max(vid_total - 1.5, 0):.2f}:d=1.5[a]")
    cmd += ["-filter_complex", ";".join(af), "-map", "0:v", "-map", "[a]"]
    if vf_parts:
        cmd += ["-vf", ",".join(vf_parts)]
    cmd += ["-t", f"{vid_total:.3f}",
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
        "status": {"privacyStatus": "unlisted", "selfDeclaredMadeForKids": False,
                   "embeddable": True},
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
    # part="status" REPLACES the whole status object: omitting embeddable here
    # silently turns embedding off ("playback disabled by the video owner").
    yt.videos().update(part="status", body={
        "id": video_id,
        "status": {"privacyStatus": "public", "selfDeclaredMadeForKids": False,
                   "embeddable": True},
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
    ap.add_argument("--fresh", action="store_true",
                    help="discard cached work for selected post(s) and regenerate")
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

        elif status == "review":
            vid = youtube_id(item.get("preview_url"))
            print(f"#{pid}: preview already uploaded; waiting for video approval")
            if args.watch and vid:
                uploaded.add(pid)

        elif status in ("needed", "rejected"):
            brief = ensure_video_brief(cfg, item)
            if not brief or not brief.get("voiceover_script"):
                print(f"#{pid}: no usable video brief — skipping")
                continue
            item["video_brief"] = brief
            print(f"#{pid}: rendering \"{item['title']}\" "
                  f"(persona: {persona or 'unknown'}, status {status})")
            workdir, manifest = prepare_workdir(item, cfg, args.fresh or status == "rejected")
            print(f"  resumable workspace: {workdir}")
            try:
                vid = manifest.get("youtube_video_id")
                if vid:
                    print(f"  resume: reusing uploaded YouTube preview {vid}")
                else:
                    final, srt = render(cfg, brief, item["title"], persona, workdir)
                    vid = upload_unlisted(yt, final, brief, item.get("permalink"))
                    manifest["youtube_video_id"] = vid
                    json_write(os.path.join(workdir, "manifest.json"), manifest)
                    if cfg.get("upload_srt", True) and not manifest.get("caption_uploaded"):
                        upload_captions(yt, vid, srt)
                        manifest["caption_uploaded"] = True
                        json_write(os.path.join(workdir, "manifest.json"), manifest)
                url = f"https://www.youtube.com/watch?v={vid}"
                mfce(cfg, "POST", "/video-ready", post_id=pid, preview_url=url)
                manifest["video_ready_sent"] = True
                json_write(os.path.join(workdir, "manifest.json"), manifest)
                print(f"#{pid}: uploaded unlisted -> {url} (Telegram sent)")
                uploaded.add(pid)
            finally:
                print(f"  resume cache kept: {workdir}")
        else:
            print(f"#{pid}: status '{status}' — nothing to do")

    if args.watch and uploaded:
        watch_for_approval(cfg, yt, uploaded,
                           cfg.get("approval_wait_minutes", 45))


if __name__ == "__main__":
    main()
