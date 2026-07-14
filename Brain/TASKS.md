# Tasks

## Blog (WordPress) — live as of 2026-07-05

- WordPress installed at `manifestedfit.com/blog` (subdirectory; funnel at root untouched). RankMath SEO installed and wizard completed. Permalinks set to Post name.
- DEFERRED (do ~week of 2026-07-13, once posts are live): set up Google Search Console (URL prefix `https://manifestedfit.com/`) and a GA4 property named "Manifested Fit" under `jborthwickog@gmail.com`, then re-run RankMath's Analytics connect step so its Search Console + Analytics dropdowns populate. Skipped during initial setup because there was no traffic yet.
- First real blog post drafted: `05_Content/blog/post-01-morning-reset-ritual.md` — needs Jonathan's own YouTube video embedded before publish.
- Next: brand the blog (teal/green/plum + logo), confirm `/blog` is linked from the main funnel nav, publish post 1 once a video exists.

## Content engine + video pipeline (added 2026-07-06)

- DONE 2026-07-11: post #73 video `PA61WiNhIYk` approved, public, and embedded; remote video queue empty.
- Enable paid-tier billing / positive prepay balance for the Gemini API project used by the worker, verify AI Studio shows the project as Paid, then run one new hybrid post and confirm `visual_provenance.json` reports at least one actual Veo beat. Current Veo requests return 429 quota exhausted.
- Run one fresh blog post through the new local-Wan hybrid backend and confirm final `visual_provenance.json` reports up to 2 `actual: local_wan` beats and total local generation remains near the 8-minute benchmark. Review the first real result before scheduling automation.
- Restart the localhost dashboard with `07_Deploy/targets/video-worker/run_dashboard.bat` before using its UI; the diagnostic dashboard process was stopped during closeout.

- Re-upload plugin v0.2.0 (`03_Website/wordpress/plugins/manifested-fit-content-engine/`) via FTP - adds [VIDEO EMBED] placeholder, video briefs, and the video REST endpoints.
- Add ~2 weeks of topics to the plugin's topic queue (it is empty; the daily cron fails loudly on an empty queue).
- Create the Bluehost cPanel daily cron (command shown on the plugin admin page) and tick Enabled.
- Verify a Run Now draft contains the [VIDEO EMBED] paragraph and `mfce_video_brief` meta.
- Google Cloud project + YouTube Data API OAuth credentials (start the API audit early - unverified apps upload locked/private).
- Get a Pexels and/or Pixabay API key for stock b-roll.
- Build `video_worker.py` per `06_Planning/VIDEO_PIPELINE_PLAN.md` and schedule it 2x daily via Task Scheduler.
- End-to-end dry run: draft -> video -> Telegram approve -> YouTube -> embedded -> publish via Telegram button.

## Next

- Use `06_Planning/AFFILIATE_APPLICATION_READINESS.md` as the next session's strategy anchor.
- Use `06_Planning/HERMES_SOCIAL_AGENT_WORKFLOW_BRIEF.md` to design a supervised Hermes/Agent Studio social workflow.
- Set up or standardize social profiles for YouTube, Pinterest, Instagram, TikTok, Facebook, and X as appropriate.
- Create a 30-day content sprint before applying to selective affiliate programs.
- Replace the placeholder affiliate CTA after Impact/Mindvalley approval.
- Add real active offers to `03_Website/public/assets/js/affiliate-offers.js` as affiliate accounts are approved.
- Decide whether lead capture should move to MailerLite, ConvertKit, Beehiiv, Brevo, or another email platform before launch.
- Generate the first Pinterest pin batch and save approved images under `Media/`.

## Later

- Add analytics and UTM tracking.
- Build a small lead dashboard or export workflow if the CSV bridge stays in use.
- Add an automated content calendar and repeatable pin publishing workflow.
- Create product review/comparison content once affiliate approval is real.
