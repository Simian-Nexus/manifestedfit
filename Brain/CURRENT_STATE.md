# Current State

Last updated: 2026-07-05

## July 2026 — active workstream: automated blog + content pipeline

The focus has shifted to building an automated, supervised blog/content engine. Full plan in `06_Planning/ACTION_PLAN_2026-07.md`; full handoff in `NEXT_CONTEXT_BRIEF.md`.

Done and working:
- WordPress blog is live at `manifestedfit.com/blog` (subdirectory; the flat funnel at root is untouched). Theme = Twenty Twenty-Five + our brand child theme `03_Website/wordpress/themes/manifestedfit-blog/`. Rank Math active (SiteSEO/SiteSEO Pro being removed as a conflict). SpeedyCache active.
- `ContentEngine` user (Editor) with an Application Password; four columnist author users (DanaCole, FrankieMoon, NadiaBrooks, RowanEllis).
- Working content pipeline: `07_Deploy/tools/push-draft.ps1` reads git-ignored `07_Deploy/targets/wordpress/config.json`, converts a Markdown file to HTML, auto-creates categories, and posts as a DRAFT via the WP REST API. Proven 2026-07-05.
- Voice team + day-of-week posting schedule decided (`05_Content/blog/authors-and-schedule.md`); categories defined (`05_Content/blog/categories.md`).

Open next steps: first real week of posts (needs the 4 author user IDs); gate the 7-Day Reset page; build the Bluehost-cron WP plugin (needs a paid AI API key, Anthropic first) for multi-provider auto-drafting; produce the first Lane A video; RankMath-optimize post 1. Safety posture unchanged: drafts only, no auto-publish, credentials stay on Jonathan's side.

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
