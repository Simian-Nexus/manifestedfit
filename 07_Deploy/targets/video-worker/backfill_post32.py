"""One-off: render + upload a video for post 32, which predates the
brief-writing plugin version. Uses a hand-written brief_post32.json and the
normal video_worker pipeline, so Telegram approval still gates the embed."""

import json
import os
import shutil
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import video_worker as vw

POST_ID = 32
TITLE = "The Hidden Mental Habit That Makes Fitness Goals Easier to Keep"
PERMALINK = "https://manifestedfit.com/blog/mental-habit-makes-fitness-goals-easier/"
PERSONA = "Dana Cole"

cfg = vw.load_config()
yt = vw.youtube_service(cfg)
with open(os.path.join(vw.HERE, "brief_post32.json"), encoding="utf-8") as f:
    brief = json.load(f)

workdir = tempfile.mkdtemp(prefix=f"mfce_video_{POST_ID}_")
try:
    final, srt = vw.render(cfg, brief, TITLE, PERSONA, workdir)
    vid = vw.upload_unlisted(yt, final, brief, PERMALINK)
    if cfg.get("upload_srt", True):
        vw.upload_captions(yt, vid, srt)
    url = f"https://www.youtube.com/watch?v={vid}"
    vw.mfce(cfg, "POST", "/video-ready", post_id=POST_ID, preview_url=url)
    print(f"post {POST_ID} uploaded unlisted -> {url} (Telegram sent)")
finally:
    shutil.rmtree(workdir, ignore_errors=True)
