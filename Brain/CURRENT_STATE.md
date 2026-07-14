# Current State

Last updated: 2026-07-11

## July 2026 — active workstream: automated blog + content pipeline

The focus has shifted to building an automated, supervised blog/content engine. Full plan in `06_Planning/ACTION_PLAN_2026-07.md`; full handoff (read this first, most current) in `NEXT_CONTEXT_BRIEF.md`.

**2026-07-11 update**: Hybrid video API failures were diagnosed and corrected locally. The worker now uses `gemini-3.5-flash` and `veo-3.1-fast-generate-preview`, runs an AI-model preflight before rendering, and reports requested versus fallback visuals clearly. The dashboard no longer returns the saved Gemini key to browser JavaScript. Live preflight passed. Generation is now resumable under `video-worker/work/post_<ID>/`: voice, hybrid plan, stock/Veo clips, in-flight Veo operation IDs, final render, and YouTube upload ID are checkpointed. Normal reruns resume; dashboard **Fresh restart** / CLI `--fresh` intentionally regenerates. Post #73's video (`PA61WiNhIYk`) is public and embedded; the remote video queue is empty.

**Hybrid provenance result**: post #77 requested 5 Veo + 6 Pexels beats, but every Veo submit returned `429 RESOURCE_EXHAUSTED` (current quota / billing), so the final was 0 Veo + 11 Pexels. Video `rL4vWim7y0M` is public and embedded. Veo requires the Gemini API project to be on a paid tier with available balance/quota. Worker now writes `visual_provenance.json`, self-heals missing WP video briefs locally, and stops further Veo submits after the first 429.

**Free local video backend installed**: official ComfyUI + Wan 2.1 T2V 1.3B on the RTX 3060 12 GB. Benchmarks at 832x480/49 frames: 12 steps = 3m33s; 20 steps = 4m03s and visibly cleaner; observed VRAM ~6.1-6.25 GB. Hybrid now defaults to `generated_video_provider=local_wan`, 20 steps, 49 frames, max 2 generated beats (~8 minutes total warm). Raw 3.06s clips are slowed to their narration-beat length and enter the normal 1080p render. Full notes: `video-worker/local-video/README.md`.

**2026-07-08 update**: the video worker is now built and has produced two live, embedded videos (see `NEXT_CONTEXT_BRIEF.md` top section for full detail) — YouTube OAuth live, `video_worker.py` + a localhost control dashboard (`07_Deploy/targets/video-worker/dashboard.py`, run via `run_dashboard.bat`) both working, plugin embed bug fixed (v0.4.2). Open: jingle/persona music (Suno), Chatterbox voice cloning decision, Task Scheduler automation, YouTube API audit request.

Done and working:
- WordPress blog is live at `manifestedfit.com/blog` (subdirectory; the flat funnel at root is untouched). Theme = Twenty Twenty-Five + our brand child theme `03_Website/wordpress/themes/manifestedfit-blog/`. Rank Math active (SiteSEO/SiteSEO Pro being removed as a conflict). SpeedyCache active.
- `ContentEngine` user (Editor) with an Application Password; four columnist author users (DanaCole, FrankieMoon, NadiaBrooks, RowanEllis).
- Working content pipeline: `07_Deploy/tools/push-draft.ps1` reads git-ignored `07_Deploy/targets/wordpress/config.json`, converts a Markdown file to HTML, auto-creates categories, and posts as a DRAFT via the WP REST API. Proven 2026-07-05.
- Voice team + day-of-week posting schedule decided (`05_Content/blog/authors-and-schedule.md`); categories defined (`05_Content/blog/categories.md`).

- Content-engine WP plugin (`03_Website/wordpress/plugins/manifested-fit-content-engine/`) is live on the site running Gemini free tier; two-way Telegram (@ManifestedFitBot) works: Publish/Keep/Trash buttons, revise-by-reply, AI chat.
- Plugin v0.2.0 (2026-07-06, needs re-upload): drafts include a `[VIDEO EMBED]` placeholder + AI video brief meta, and three secret-guarded REST endpoints (`video-queue`/`video-ready`/`video-embed`) let an external video worker attach approved YouTube videos with Telegram approval buttons. Plan: `06_Planning/VIDEO_PIPELINE_PLAN.md`.

Open next steps: re-upload plugin v0.2.0, add topics to the queue, create the Bluehost cPanel daily cron (then daily drafting is fully automatic); build the local video worker (edge-tts + Pexels + ffmpeg + YouTube API per the plan); first real week of posts; gate the 7-Day Reset page; RankMath-optimize post 1. Safety posture unchanged: drafts only, publish only via Jonathan's Telegram approval, credentials stay on Jonathan's side.

## Project Shape

Manifested Fit is being rebuilt as a Codex-friendly affiliate funnel for `manifestedfit.com`.

The project is now a Git repository on branch `main` with remote `origin` set to `https://github.com/Simian-Nexus/manifestedfit.git`.

The project now has:

- a lightweight static/PHP website scaffold in `03_Website/public/`
- an interactive 7-day mind-body reset lead magnet
- a public resources page backed by `03_Website/public/assets/js/affiliate-offers.js`
- initial strategy, content, launch, and deployment docs
- a target-folder deployment pattern where `07_Deploy/targets/prod/config.json` is the ignored production FTP config
- a local memory system in `Brain/`

Local verification has passed for PHP syntax, PowerShell deploy-script parsing, main page responses, and the starter POST lead capture endpoint.

The live site now serves `/resources/` and the public affiliate-offer registry script.

## Working Assumptions

- Initial hosting is shared hosting reachable by FTP or FTPS.
- The first production version should stay simple and publishable without a Node runtime.
- The email capture endpoint is a starter bridge, not the final email marketing system.
- The old archive is reference material, not the working source.

## External Notes

Mindvalley is the initial aspirational affiliate offer. Its official affiliate page currently points applications to Impact and says the affiliate dashboard is hosted on Impact.

As of 2026-05-03, the next strategic focus is affiliate-application readiness. The user has very limited social presence in this niche, so future sessions should prioritize profile setup, content cadence, lead capture/email platform decisions, lower-barrier affiliate programs, and a supervised Hermes/AI Command Bridge social assistant workflow before treating selective programs like Mindvalley as ready-to-apply.
