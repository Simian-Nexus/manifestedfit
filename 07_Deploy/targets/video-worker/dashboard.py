"""Manifested Fit video pipeline dashboard (localhost only).

One place to: edit config.json via forms, sample TTS voices (edge catalog
or Chatterbox reference clips), see the video queue, kick off worker runs,
and rebuild branding - instead of editing JSON and running .py/.bat files.

Start: run_dashboard.bat  (serves http://localhost:8765, local machine only)
"""

import glob
import json
import os
import subprocess
import sys
import threading
import time

import requests
from flask import Flask, jsonify, request, send_from_directory

HERE = os.path.dirname(os.path.abspath(__file__))
CONFIG = os.path.join(HERE, "config.json")
SAMPLES = os.path.join(HERE, "samples")
REFS = os.path.join(HERE, "refs")
LOG = os.path.join(HERE, "dashboard_run.log")
os.makedirs(SAMPLES, exist_ok=True)
os.makedirs(REFS, exist_ok=True)

UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) MFCE-dashboard"}
SAMPLE_TEXT = ("I put the phone face down and did something small instead. "
               "Five minutes, no app, no perfect setup.")

app = Flask(__name__)
_worker = {"proc": None}


def load_config():
    with open(CONFIG, encoding="utf-8") as f:
        return json.load(f)


def save_config(cfg):
    with open(CONFIG, "w", encoding="utf-8") as f:
        json.dump(cfg, f, indent=2)


def worker_running():
    return _worker["proc"] is not None and _worker["proc"].poll() is None


# ------------------------------------------------------------------ api

@app.get("/api/state")
def api_state():
    cfg = load_config()
    gemini_api_key_set = bool(cfg.get("gemini_api_key"))
    cfg = {k: v for k, v in cfg.items() if k not in
           ("mfce_cron_secret", "pexels_api_key", "gemini_api_key")}
    music = {}
    base = os.path.join(HERE, "music")
    for d in sorted(glob.glob(os.path.join(base, "*"))):
        if os.path.isdir(d):
            music[os.path.basename(d)] = len(
                [f for e in ("*.mp3", "*.wav", "*.m4a")
                 for f in glob.glob(os.path.join(d, e))])
    refs = [os.path.basename(f) for f in glob.glob(os.path.join(REFS, "*.wav"))]
    branding = {n: os.path.exists(os.path.join(HERE, "branding", n))
                for n in ("intro.mp4", "endcard.mp4", "endcard.png", "jingle.mp3", "ding.mp3")}
    return jsonify({"config": cfg, "gemini_api_key_set": gemini_api_key_set,
                    "music": music, "refs": refs,
                    "branding": branding, "worker_running": worker_running()})


@app.post("/api/config")
def api_config():
    cfg = load_config()
    incoming = request.get_json(force=True)
    for key in ("tts_engine", "tts_voice", "voices", "outros", "watermark",
                "music_volume", "burn_captions", "upload_srt",
                "approval_wait_minutes", "endcard_hold_seconds",
                "visuals_engine", "gemini_api_key", "veo_model",
                "veo_max_clips_per_video", "visual_qa", "gemini_qa_model",
                "generated_video_provider", "local_wan_max_clips_per_video",
                "local_wan_steps", "local_wan_frames"):
        # A blank secret means "keep the saved value". This lets the dashboard
        # mask credentials without erasing them on the next settings save.
        if key == "gemini_api_key" and not incoming.get(key):
            continue
        if key in incoming:
            cfg[key] = incoming[key]
    save_config(cfg)
    return jsonify({"ok": True})


@app.get("/api/queue")
def api_queue():
    cfg = load_config()
    r = requests.get(cfg["mfce_base_url"] + "/video-queue",
                     params={"secret": cfg["mfce_cron_secret"]},
                     headers=UA, timeout=30)
    r.raise_for_status()
    return jsonify(r.json())


@app.post("/api/worker")
def api_worker():
    if worker_running():
        return jsonify({"ok": False, "error": "worker already running"}), 409
    args = [sys.executable, os.path.join(HERE, "video_worker.py")]
    body = request.get_json(force=True) or {}
    if body.get("watch"):
        args.append("--watch")
    if body.get("fresh"):
        args.append("--fresh")
    if body.get("post"):
        args += ["--post", str(int(body["post"]))]
    logf = open(LOG, "w", encoding="utf-8")
    _worker["proc"] = subprocess.Popen(args, stdout=logf, stderr=subprocess.STDOUT,
                                       cwd=HERE, text=True)
    return jsonify({"ok": True})


@app.get("/api/log")
def api_log():
    text = ""
    if os.path.exists(LOG):
        with open(LOG, encoding="utf-8", errors="replace") as f:
            text = f.read()[-8000:]
    return jsonify({"running": worker_running(), "log": text})


@app.post("/api/branding")
def api_branding():
    p = subprocess.run([sys.executable, os.path.join(HERE, "build_branding.py")],
                       capture_output=True, text=True, cwd=HERE)
    return jsonify({"ok": p.returncode == 0,
                    "output": (p.stdout + p.stderr)[-2000:]})


@app.get("/api/voices")
def api_voices():
    p = subprocess.run([sys.executable, "-m", "edge_tts", "--list-voices"],
                       capture_output=True, text=True)
    voices = []
    for line in p.stdout.splitlines():
        name = line.split()[0] if line.strip() else ""
        if name.startswith("en-"):
            voices.append(name)
    return jsonify(sorted(set(voices)))


@app.post("/api/sample")
def api_sample():
    body = request.get_json(force=True)
    engine = body.get("engine", "edge")
    text = body.get("text") or SAMPLE_TEXT
    stamp = str(int(time.time()))
    try:
        sys.path.insert(0, HERE)
        import video_worker as vw
        if engine == "chatterbox":
            vcfg = {"exaggeration": float(body.get("exaggeration", 0.5)),
                    "cfg_weight": float(body.get("cfg_weight", 0.5))}
            if body.get("ref"):
                vcfg["ref_wav"] = os.path.join(REFS, os.path.basename(body["ref"]))
            out = vw.tts_chatterbox(text, vcfg, SAMPLES, f"sample_{stamp}")
        else:
            out = vw.tts_edge(text, {"edge": body.get("voice", "en-US-JennyNeural")},
                              SAMPLES, f"sample_{stamp}")
        return jsonify({"ok": True, "url": "/samples/" + os.path.basename(out)})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.get("/samples/<path:name>")
def get_sample(name):
    return send_from_directory(SAMPLES, name)


# ------------------------------------------------------------------ page

PAGE = """<!doctype html><html><head><meta charset="utf-8">
<title>Manifested Fit - Video Pipeline</title>
<style>
:root{color-scheme:light dark}
body{font-family:system-ui,sans-serif;max-width:980px;margin:1.5em auto;padding:0 1em;line-height:1.45}
h1{font-size:1.4em} h2{font-size:1.1em;margin-top:1.6em;border-bottom:1px solid #8884;padding-bottom:.2em}
table{border-collapse:collapse;width:100%} td,th{padding:.3em .6em;border-bottom:1px solid #8883;text-align:left;font-size:.92em}
button{padding:.45em 1em;border-radius:6px;border:1px solid #8886;cursor:pointer;margin:.15em}
button.primary{background:#2a6e4f;color:#fff;border-color:#2a6e4f}
input,select,textarea{padding:.35em;border-radius:5px;border:1px solid #8886;font:inherit;background:transparent;color:inherit}
textarea{width:100%;min-height:3em} .row{display:flex;gap:1em;flex-wrap:wrap;align-items:end;margin:.4em 0}
.card{border:1px solid #8884;border-radius:10px;padding:.8em 1em;margin:.6em 0}
pre{background:#8881;padding:.7em;border-radius:8px;max-height:16em;overflow:auto;font-size:.8em;white-space:pre-wrap}
.badge{padding:.1em .55em;border-radius:99px;font-size:.8em;border:1px solid #8886}
.ok{color:#2a8f5a}.warn{color:#c07a00}
label{font-size:.85em;display:block;opacity:.8}
</style></head><body>
<h1>🎬 Manifested Fit — Video Pipeline</h1>

<h2>Queue & actions</h2>
<div class="card">
  <div id="queue">loading…</div>
  <div class="row">
    <button class="primary" onclick="runWorker(true)">▶ Run pipeline (render → approve → embed)</button>
    <button onclick="runWorker(false)">Run once (no approval wait)</button>
    <label title="Discard saved voice, plan, clips, and incomplete Veo jobs"><input id="run_fresh" type="checkbox"> Fresh restart (ignore resume cache)</label>
    <button onclick="rebuildBranding()">Rebuild intro/endcard</button>
    <span id="workerstate" class="badge"></span>
  </div>
  <pre id="log" style="display:none"></pre>
</div>

<h2>Voice sampler</h2>
<div class="card">
  <div class="row">
    <div><label>Engine</label><select id="s_engine" onchange="engineUI()">
      <option value="edge">edge-tts (catalog)</option>
      <option value="chatterbox">Chatterbox (clone/local)</option></select></div>
    <div id="s_edge"><label>Voice</label><select id="s_voice"></select></div>
    <div id="s_cb" style="display:none">
      <label>Reference clip (refs/*.wav; empty = built-in voice)</label><select id="s_ref"><option value="">built-in</option></select>
      <label>Exaggeration <span id="exv">0.5</span></label>
      <input type="range" id="s_ex" min="0.2" max="1" step="0.05" value="0.5" oninput="exv.textContent=this.value">
    </div>
    <button class="primary" onclick="sample()">🔊 Generate sample</button>
  </div>
  <div class="row"><input id="s_text" style="flex:1" placeholder="Custom sample text (optional)"></div>
  <audio id="s_audio" controls style="width:100%;display:none"></audio>
  <div id="s_status"></div>
  <p style="font-size:.85em;opacity:.7">Chatterbox voices come from reference clips: drop a clean 10–20s
  WAV into the <code>refs\\</code> folder and it appears here. Assign your pick per persona below.</p>
</div>

<h2>Personas — voice & outro</h2>
<div id="personas"></div>

<h2>General settings</h2>
<div class="card"><div class="row">
  <div><label>TTS engine</label><select id="c_engine"><option>edge</option><option>chatterbox</option></select></div>
  <div><label>Music volume</label><input id="c_musvol" type="number" step="0.01" min="0" max="1" style="width:5em"></div>
  <div><label>Burn captions</label><input id="c_burn" type="checkbox"></div>
  <div><label>Upload SRT to YouTube</label><input id="c_srt" type="checkbox"></div>
  <div><label>Watermark</label><input id="c_wm"></div>
  <div><label>Approval wait (min)</label><input id="c_wait" type="number" style="width:5em"></div>
</div>
<div class="row">
  <div><label>Visuals engine</label><select id="c_vis">
    <option value="pexels">Pexels stock (free)</option>
    <option value="hybrid">Hybrid: AI picks Veo or stock per beat</option>
    <option value="veo">Google Veo only (generative, uses credits)</option></select></div>
  <div><label>Generated-video provider</label><select id="c_genprovider">
    <option value="local_wan">Local Wan 2.1 (free, slower)</option>
    <option value="veo">Google Veo (paid)</option></select></div>
  <div><label>AI frame check (match visuals to narration)</label><input id="c_qa" type="checkbox"></div>
  <div><label>Gemini API key (planner/QA/Veo)</label><input id="c_gkey" type="password" autocomplete="new-password" style="width:16em" placeholder="Enter only to replace saved key"></div>
  <div><label>Veo model</label><input id="c_vmodel" style="width:18em" placeholder="veo-3.1-fast-generate-preview"></div>
  <div><label>Veo max clips/video</label><input id="c_vmax" type="number" min="1" max="20" style="width:5em"></div>
  <div><label>Local Wan clips/video</label><input id="c_lmax" type="number" min="1" max="4" style="width:5em"></div>
  <div><label>Local Wan steps</label><input id="c_lsteps" type="number" min="4" max="50" style="width:5em"></div>
</div>
<div id="assets" style="font-size:.9em;margin-top:.5em"></div>
<div class="row"><button class="primary" onclick="saveConfig()">💾 Save settings</button><span id="savestate"></span></div>
</div>

<script>
let CFG=null, PERSONAS=["Dana Cole","Nadia Brooks","Frankie Moon","Rowan Ellis"];
async function j(u,opt){const r=await fetch(u,opt);return r.json()}
function engineUI(){const cb=s_engine.value==='chatterbox';s_cb.style.display=cb?'':'none';s_edge.style.display=cb?'none':''}
async function load(){
  const st=await j('/api/state');CFG=st.config;
  c_engine.value=CFG.tts_engine||'edge';c_musvol.value=CFG.music_volume??0.09;
  c_burn.checked=CFG.burn_captions!==false;c_srt.checked=CFG.upload_srt!==false;
  c_wm.value=CFG.watermark||'';c_wait.value=CFG.approval_wait_minutes||45;
  c_vis.value=CFG.visuals_engine||'pexels';c_gkey.value='';
  c_genprovider.value=CFG.generated_video_provider||'veo';
  c_gkey.placeholder=st.gemini_api_key_set?'Saved (masked) — enter only to replace':'No key saved';
  c_vmodel.value=CFG.veo_model||'';c_vmax.value=CFG.veo_max_clips_per_video||8;
  c_lmax.value=CFG.local_wan_max_clips_per_video||2;c_lsteps.value=CFG.local_wan_steps||20;
  c_qa.checked=CFG.visual_qa!==0&&CFG.visual_qa!==false;
  let a=`Branding: intro ${st.branding['intro.mp4']?'✅':'❌'} · endcard ${st.branding['endcard.mp4']?'✅ (animated)':(st.branding['endcard.png']?'⚠️ static only (rebuild for bell animation)':'❌')} · jingle ${st.branding['jingle.mp3']?'✅':'❌ (drop branding\\\\jingle.mp3 then rebuild)'}<br>Music tracks: `;
  a+=Object.entries(st.music).map(([k,v])=>`${k}: ${v||'❌ 0'}`).join(' · ')||'none';
  assets.innerHTML=a;
  s_ref.innerHTML='<option value="">built-in</option>'+st.refs.map(r=>`<option>${r}</option>`).join('');
  personas.innerHTML=PERSONAS.map(p=>{const v=(CFG.voices||{})[p]||{};const o=(CFG.outros||{})[p]||'';
    return `<div class="card"><b>${p}</b><div class="row">
    <div><label>edge voice</label><input data-p="${p}" data-k="edge" value="${v.edge||''}" style="width:15em"></div>
    <div><label>ref wav (chatterbox)</label><input data-p="${p}" data-k="ref_wav" value="${v.ref_wav||''}" style="width:12em" placeholder="refs/name.wav"></div>
    <div><label>exaggeration</label><input data-p="${p}" data-k="exaggeration" type="number" step="0.05" min="0" max="1" value="${v.exaggeration??0.5}" style="width:5em"></div>
    </div><label>Outro line</label><textarea data-p="${p}" data-k="outro">${o}</textarea></div>`}).join('');
  const vs=await j('/api/voices');s_voice.innerHTML=vs.map(v=>`<option>${v}</option>`).join('');
  refreshQueue();poll();
}
async function refreshQueue(){
  try{const q=await j('/api/queue');
  queue.innerHTML=q.length?'<table><tr><th>Post</th><th>Title</th><th>Persona</th><th>Status</th><th>Preview</th></tr>'+
    q.map(i=>`<tr><td>${i.post_id}</td><td>${i.title}</td><td>${i.persona||''}</td><td><span class="badge">${i.video_status}</span></td><td>${i.preview_url?`<a href="${i.preview_url}" target="_blank">▶</a>`:''}</td></tr>`).join('')+'</table>'
    :'Queue is empty — nothing needs a video right now.';}
  catch(e){queue.textContent='Could not reach the site: '+e}
}
async function runWorker(watch){await j('/api/worker',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({watch,fresh:run_fresh.checked})});poll()}
async function poll(){const l=await j('/api/log');workerstate.textContent=l.running?'worker: running':'worker: idle';
  workerstate.className='badge '+(l.running?'warn':'ok');
  if(l.log){log.style.display='';log.textContent=l.log;log.scrollTop=log.scrollHeight}
  if(l.running)setTimeout(poll,3000);else refreshQueue()}
async function rebuildBranding(){const r=await j('/api/branding',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});alert(r.output)}
async function sample(){
  s_status.textContent='generating… (chatterbox first run loads the model, ~30s)';
  const body={engine:s_engine.value,voice:s_voice.value,ref:s_ref.value,exaggeration:s_ex.value,text:s_text.value};
  const r=await j('/api/sample',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  if(r.ok){s_audio.src=r.url;s_audio.style.display='';s_audio.play();s_status.textContent=''}
  else s_status.textContent='failed: '+r.error;
}
async function saveConfig(){
  const voices={...(CFG.voices||{})},outros={...(CFG.outros||{})};
  document.querySelectorAll('[data-p]').forEach(el=>{const p=el.dataset.p,k=el.dataset.k;
    if(k==='outro'){if(el.value.trim())outros[p]=el.value.trim()}
    else{voices[p]=voices[p]||{};const v=el.value.trim();
      if(k==='exaggeration')voices[p][k]=parseFloat(el.value);else if(v)voices[p][k]=v;else delete voices[p][k]}});
  const payload={
    tts_engine:c_engine.value,music_volume:parseFloat(c_musvol.value),burn_captions:c_burn.checked,
    upload_srt:c_srt.checked,watermark:c_wm.value,approval_wait_minutes:parseInt(c_wait.value),
    visuals_engine:c_vis.value,veo_model:c_vmodel.value,
    generated_video_provider:c_genprovider.value,
    veo_max_clips_per_video:parseInt(c_vmax.value)||8,
    local_wan_max_clips_per_video:parseInt(c_lmax.value)||2,
    local_wan_steps:parseInt(c_lsteps.value)||20,
    local_wan_frames:49,visual_qa:c_qa.checked?1:0,voices,outros};
  if(c_gkey.value.trim())payload.gemini_api_key=c_gkey.value.trim();
  await j('/api/config',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  savestate.textContent='saved ✅';setTimeout(()=>savestate.textContent='',2000);load();
}
load();
</script></body></html>"""


@app.get("/")
def index():
    return PAGE


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8765, debug=False)
