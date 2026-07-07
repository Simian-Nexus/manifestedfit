# Session Log

## 2026-05-01

- Created the initial Manifested Fit project scaffold.
- Added local project instructions, memory files, strategy docs, content draft folders, website files, and FTP publish tooling.
- Verified the project was not yet a Git repository.
- Reviewed newer sibling-project deployment patterns, especially the FTP publish-helper style from `Spinning_Monkey_Web`.
- Confirmed the official Mindvalley affiliate application path points to Impact and documents a baseline 30% commission with a 30-day cookie.
- Verified PHP syntax, PowerShell deploy-script parsing, local page responses, and the starter lead capture endpoint.
- Created ignored production FTP config with the server, username, and port; password remains a local placeholder.
- Initialized Git, connected the GitHub remote, excluded local credential files and `Archive/`, committed the initial scaffold, and pushed `main`.
- Renamed the deployment config layout so target folders carry intent: production credentials now live in ignored `07_Deploy/targets/prod/config.json`, and localhost preview guidance lives in `07_Deploy/targets/local-preview/`.
- Renamed the production deployment target from a host-specific name to `prod` so the project is not tied to a hosting provider in folder names or committed docs.
- First production publish attempt reached the upload step but failed before transfer because explicit FTPS reported a certificate principal-name mismatch for the configured FTP host.
- Updated the ignored production FTP config to use the certificate-matching FTPS host pattern from sibling projects.
- Published all 14 files from `03_Website/public/` to the production FTP account and verified remote hashes.
- Public `manifestedfit.com` currently resolves to `162.241.244.144`, while the FTP/web account that received the upload is `162.241.244.106`; forcing the domain to `162.241.244.106` returns the Manifested Fit page, so DNS/hosting assignment still needs alignment.

## 2026-05-02

- Added `03_Website/public/assets/js/affiliate-offers.js` as the public offer registry.
- Added `/resources/` as the public resource and affiliate-offer hub.
- Updated the homepage, thank-you page, lead magnet, and legal nav to include resources where useful.
- Locally tested main pages and the opt-in endpoint, removed the fake test CSV, then published 17 public files to production FTP with hash verification.
- Confirmed `http://manifestedfit.com/resources/` returns 200 and references the offer registry.

## 2026-05-03

- Added `06_Planning/AFFILIATE_APPLICATION_READINESS.md` to focus the next fresh context on audience proof and application readiness before applying to selective affiliate programs.
- Added `06_Planning/HERMES_SOCIAL_AGENT_WORKFLOW_BRIEF.md` to connect Manifested Fit growth work with the Hermes/AI Command Bridge Agent Studio direction.
- Updated `NEXT_CONTEXT_BRIEF.md`, `Brain/CURRENT_STATE.md`, and `Brain/TASKS.md` so future sessions know the user has minimal current niche social presence and wants a supervised social media assistance workflow.

## 2026-07-06 (late session)

- Plugin confirmed live on the site with Gemini free tier; two-way Telegram chat verified working. Noted a historical Telegram 403 ("bot can't message the bot") from a briefly-wrong chat_id.
- Built plugin v0.2.0 (video pipeline, blog side): prompt-enforced [VIDEO EMBED] placeholder + structured video_brief post meta on every non-solemn draft; REST endpoints video-queue / video-ready / video-embed (cron-secret guarded); Telegram Approve video / Reject video buttons; video-embed swaps the placeholder for a responsive YouTube embed block and re-notifies with Publish buttons; revise-by-reply now shields the placeholder/embed from the AI. PHP lint clean. Needs re-upload via FTP.
- Wrote 06_Planning/VIDEO_PIPELINE_PLAN.md: external worker loop, Option A recommendation (free local Python worker: edge-tts + Pexels/Pixabay + ffmpeg + YouTube Data API, Task Scheduler 2x daily), Make.com alternative, YouTube OAuth setup notes.
- Immediate go-live items identified: add topics to the queue (it is empty) and create the Bluehost cPanel daily cron.
- Plugin v0.3.0 (same session): accordion admin page (details/summary sections), inline-editable topics with per-topic persona + provider/model overrides, AI topic fallback when the queue is empty (day/holiday/weekend/persona/recent-titles aware, flagged in queue + Telegram). Advised on cPanel cron fields.
- Plugin v0.4.0: model dropdowns per provider (known ids + free-text override; Anthropic list incl. claude-fable-5/claude-sonnet-5, default stays claude-opus-4-8); "Ask AI to plan the queue" button with custom-instructions box -> proposal stored and shown in a review modal with Apply (replaces pending queue) / Discard. Advised exact cPanel cron fields (0 6 * * * + curl command) and flagged that Jonathan's pasted topic list added notes lines as separate topics. Anthropic key now obtained.
