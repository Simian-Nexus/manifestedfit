# Next Context Brief

**Last updated: 2026-07-06.** Point a fresh session at this file to continue seamlessly.

## What this project is

Manifested Fit is a faceless AI wellness/manifestation brand with two income goals: YouTube ad revenue + affiliate commissions, supported by a blog on `manifestedfit.com`. The active workstream (July 2026) is **standing up an automated blog + content pipeline** with Claude as a supervised content engine. The strategy is fully written in `06_Planning/ACTION_PLAN_2026-07.md` (two lanes: Lane A voice-led is primary/money; Lane B stylized character is a test). Read that plan first.

## Current state (what's done and working)

- **WordPress blog is live at `manifestedfit.com/blog`** — installed in a subdirectory so the existing hand-built funnel at the site root is untouched. Runs on Bluehost.
- **Active theme:** Twenty Twenty-Five + a custom **brand child theme** we built: `03_Website/wordpress/themes/manifestedfit-blog/` (theme.json carries the brand palette teal `#08736f` / green / plum / cream + Inter; matches the flat site's `styles.css`). Installed via FileZilla, activated. SpeedyCache is active — clear it after changes. (Inter font not yet installed via Font Library; theme falls back to system sans. Claude offered to hard-wire Inter into the theme — not yet done.)
- **SEO:** Rank Math installed + wizard done. Google Search Console/Analytics deliberately deferred (see TASKS.md, ~week of 2026-07-13). **SiteSEO + SiteSEO Pro** (Softaculous installer defaults) are being removed as a duplicate-SEO-plugin conflict — deactivate SiteSEO Pro first, then SiteSEO.
- **Users:** `ContentEngine` (role **Editor**) is the API/posting user, with an Application Password created. Four persona/columnist authors exist (role Author): **DanaCole, FrankieMoon, NadiaBrooks, RowanEllis**.
- **The content pipeline works end-to-end.** `07_Deploy/tools/push-draft.ps1` (PowerShell, matches existing deploy tooling) reads git-ignored `07_Deploy/targets/wordpress/config.json` (site_url, username=ContentEngine, app_password), parses a Markdown file + front matter, converts to HTML, auto-creates categories by name, and **always posts as a draft** via the WP REST API. Proven working 2026-07-05 (test draft + categories created). Jonathan runs it locally; credentials never enter chat. Script is ASCII-only (an em-dash once broke Windows PowerShell 5.1 parsing — keep it ASCII).

## Progress 2026-07-06 (this session)

- **push-draft.ps1 fixed**: WP returns category names HTML-entity-encoded ("Rituals &amp;amp; Routines"), which broke the exact-name match and caused a 400 `term_exists` on re-create. Script now decodes entities and recovers the existing term id from the error body. Verified working (created drafts 28/29 "Pipeline Test" - **both should be trashed** along with any older test drafts).
- **Author IDs partially resolved via REST**: FrankieMoon = 4, RowanEllis = 5 (very likely - authored the 7-Day Reset page), ContentEngine = 6, admin = 1. Dana and Nadia are 2/3 in unknown order (Editor role can't list users). Moot for the plugin (resolves by login name), still needed for push-draft front-matter `author_id`.
- **Telegram wired end-to-end**: new bot **@ManifestedFitBot** ("Manifested Fit Engine"). Token + chat_id (6617208680) in git-ignored `07_Deploy/targets/telegram/config.json`. Test message sent successfully from PowerShell.
- **Content-engine WP plugin BUILT** (not yet uploaded/activated): `03_Website/wordpress/plugins/manifested-fit-content-engine/` - settings page (API key, Telegram, model default claude-opus-4-8), topic queue, persona schedule incl. holiday/solemn rules, Run Now button, secret-guarded REST cron endpoint + CLI cron-runner.php fallback, structured-JSON generation, RankMath focus keyword, drafts-only. See its README.md for install + Bluehost cron steps.
- **Two-way Telegram added to the plugin**: an "Enable two-way Telegram" button registers the bot webhook at `/wp-json/mfce/v1/telegram` (secret-token header verified, chat-id restricted, update_id deduped). Draft notices then carry Publish/Keep/Trash buttons (tapping Publish = the human approval; the engine never auto-publishes), replying to a notice makes the AI revise that draft, and any other message is answered by the configured model. Clarified for Jonathan: his claude.ai subscription CANNOT power the plugin/bot - server-side calls need the metered API key (already the plan).
- **Multi-provider support DONE (phase c)**: provider dropdown now offers Anthropic (default, claude-opus-4-8), Google Gemini (gemini-2.5-flash; Jonathan has a free-tier key - caveat: Google may train on free-tier API content), OpenAI (gpt-5.1), Grok/xAI (grok-4), and Custom/local OpenAI-compatible (e.g. Ollama on a VM - base URL + optional key + model; must be reachable FROM Bluehost, so a home box needs a tunnel like Tailscale Funnel/cloudflared). One structured-JSON pipeline for all; the custom provider degrades json_schema -> json_object -> prompted JSON for smaller local models. Provider drives generation, revision, and Telegram chat.
- **Remaining to go live**: Jonathan creates paid Anthropic API key (console.anthropic.com) - or flips provider to Gemini with his free key - uploads plugin via FTP, activates, pastes keys, tests Run Now, adds Bluehost cron.
- **Plugin IS live on the site** (later on 2026-07-06): Jonathan uploaded/activated it, set provider = Gemini (free tier), and two-way Telegram chat works. One historical `403 can't send messages to the bot` in the log means chat_id was briefly set to the bot's own id - now believed fixed (chat works). Topic queue is EMPTY and the Bluehost cPanel cron may not be created yet - both are needed before daily drafting starts.
- **Plugin v0.3.0 additions (same session)**: admin page reorganized into collapsible `<details>` sections (Status & actions / Engine & AI provider / Telegram / Cron & video endpoints / Topic queue / Recent activity); pending topics are editable in place and can pin a persona and/or provider+model per topic (falls back to default provider if the pinned one has no key); "AI topic fallback" setting (default on) - empty queue makes the AI pick today's topic from day-of-week/holiday/weekend/persona/recent-titles context, adds it to the queue flagged AI-picked, and notes it in the Telegram message. Jonathan's pasted topic list has stray rows (notes lines added as separate topics) - fix via the new inline editing. Bluehost cPanel cron still to be created (fields: minute 0, hour 6, day *, month *, weekday *, command = curl line from the plugin admin page).
- **Plugin v0.4.0 additions (same session)**: per-provider model DROPDOWNS (curated known ids + free-text override box that wins when filled; Anthropic list: claude-opus-4-8 default, claude-fable-5, claude-sonnet-5, 4.7/4.6/haiku); "Ask AI to plan the queue" (textarea for custom asks like seasonal week-long series) -> AI proposal stored in `mfce_topic_plan` option and rendered as a review MODAL with Apply (replaces pending topics, keeps used history) / Discard. Jonathan now HAS an Anthropic API key. His pasted topic list has stray rows (note lines became separate topics) - fix via inline edit or the AI planner. cPanel cron fields advised: minute 0, hour 6, rest *, command = the curl line from the plugin page (server timezone may differ from Pacific).
- **Plugin v0.2.0 - video pipeline (this session, built, NEEDS RE-UPLOAD via FTP)**: every non-solemn draft now carries a `<p>[VIDEO EMBED]</p>` placeholder (prompt-enforced) + an AI-written YouTube video brief in post meta (`mfce_video_brief`: yt title/description/voiceover script/visual direction; status meta `mfce_video_status`: needed→review→approved|rejected→embedded). Three new cron-secret-guarded REST endpoints for an external video worker: `GET /wp-json/mfce/v1/video-queue`, `POST /video-ready` (sends Telegram Approve video/Reject video buttons), `POST /video-embed` (swaps placeholder for a responsive YouTube embed block + Telegram notice with Publish buttons). Revise-by-reply now shields the placeholder/embed from the AI. Admin page lists the exact endpoint URLs. Workflow-side plan (video generation options, YouTube API setup, worker loop): `06_Planning/VIDEO_PIPELINE_PLAN.md` - recommended Option A = free local Python worker (edge-tts + Pexels + ffmpeg + YouTube Data API) on Task Scheduler; worker itself NOT built yet.

## The voice team + schedule (decided)

Four columnist personas, each a voice (spec + bios in `05_Content/blog/authors-and-schedule.md`):
- **Dana Cole** — grounded & practical. **Nadia Brooks** — warm & story-led. **Frankie Moon** — playful & good-vibes. **Rowan Ellis** — calm & minimal.

Posting schedule by day's mood: **Mon Frankie, Tue Dana, Wed Nadia, Thu Dana, Fri Rowan; Sat/Sun random.** Canadian statutory holidays → Frankie (happy vibe) — **except** National Day for Truth and Reconciliation (Sep 30) and Remembrance Day (Nov 11), which are solemn: use Rowan's calm voice or skip. Personas are voice bylines only — never fabricate credentials or use AI headshots-as-real-people; add an honest "About our voices" note.

## Content assets already written

- `05_Content/blog/post-01-morning-reset-ritual.md` — first post, original single-voice draft (with RankMath field values).
- `05_Content/blog/post-01-voice-variations.md` — the same post in all 4 voices (for the voice test).
- The **Golden Retriever** version (Frankie) is already a draft in WordPress, authored by FrankieMoon.
- `05_Content/lead-magnet/7-day-reset-content.md` — the written 7-Day Mind-Body Reset (Rowan voice); exists as a **draft page** in WP, needs gating behind email signup.
- `05_Content/blog/categories.md` — taxonomy: Manifestation, Mindset, Rituals & Routines, Gentle Movement, Sleep & Rest, Recipes.

## Open threads / next steps (see the task list)

1. **Finish removing SiteSEO/SiteSEO Pro**, trash the "Pipeline Test" draft.
2. **First real week of posts:** Claude writes Mon–Fri in the rotation; Jonathan pushes them. **BLOCKER:** needs the four author user IDs (Users → hover persona → `user_id=N`) to stamp bylines via front-matter `author_id`.
3. **Optimize post 1 in RankMath** (focus keyword, meta, slug, featured image) — needs a real embedded video first.
4. **Gate the 7-Day Reset page** behind email signup (GoSMTP is installed for delivery).
5. **The self-running engine (Jonathan's vision):** Bluehost cron → custom WP plugin → routes each scheduled topic to a chosen AI provider (Claude/OpenAI/Gemini) → creates a DRAFT. Build phased: (a) API key = paid metered API, NOT the Claude subscription, NOT OAuth — Anthropic first; (b) plugin MVP one provider + cron + topic queue; (c) multi-provider routing + featured images; (d) optional auto-publish per lane once trusted.
6. **Produce the first Lane A video** (from the idea bank) so post 1 can publish with a real YouTube embed.
7. **Recipe Shorts pilot** with an AI avatar (sparked by a "detox smoothie" test) → the Recipes category.

## Safety posture (unchanged)

Supervised drafting/reporting. Nothing publishes itself (push-draft forces draft; the plugin should too, initially). Claude does not hold Jonathan's passwords/API keys in chat — credentials live in git-ignored files on his side; Claude writes code, Jonathan runs it. No submitting affiliate applications, no changing accounts.

## Important paths

- Action plan (read first): `06_Planning/ACTION_PLAN_2026-07.md`
- Voice team + schedule: `05_Content/blog/authors-and-schedule.md`
- Categories: `05_Content/blog/categories.md`
- Push-draft tool: `07_Deploy/tools/push-draft.ps1` + `07_Deploy/targets/wordpress/config.example.json`
- Brand child theme: `03_Website/wordpress/themes/manifestedfit-blog/`
- Flat funnel site (root): `03_Website/public/` (brand tokens in `assets/css/styles.css`)
- Affiliate offer registry (still placeholders): `03_Website/public/assets/js/affiliate-offers.js`
- Weekly report task output: `06_Planning/reports/`
- Task list source of truth for follow-ups: `Brain/TASKS.md`

## Still-relevant older context

Affiliate offers remain placeholders (Mindvalley is aspirational — Impact-based, ~30% commission, high ~75k-follower bar; don't gate launch on it). Email capture on the flat site still writes to CSV; deciding on a real email platform (MailerLite/Beehiiv/ConvertKit) is still open. `Archive/Pre_2026-05-01/` holds reusable old scripts/videos.
