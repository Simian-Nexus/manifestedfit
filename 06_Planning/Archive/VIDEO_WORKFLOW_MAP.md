# Video Workflow Map

> **Status:** 📦 Archived 2026-07-14 — historical. A one-time GenSpark-to-Codex concept mapping from the project's early conception (2026-05-01), since superseded by what was actually built. Kept for provenance only; not a working reference.

Source video: `https://www.youtube.com/watch?v=42UsykZzCRQ`

## Affiliate Platform Answer

The product in the transcript is Mindvalley. Mindvalley's official affiliate page links its application button to Impact and says affiliates log in to a dashboard hosted on Impact. The page also lists a baseline commission of 30% and a 30-day cookie.

Useful official links:

- `https://www.mindvalley.com/affiliates`
- `https://www.mindvalley.com/partnerships`

## GenSpark Piece To Codex Piece

| Video step | GenSpark version | Manifested Fit/Codex version |
| --- | --- | --- |
| Hub | GenSpark project hub | This project root plus `Brain/`, `06_Planning/`, and `NEXT_CONTEXT_BRIEF.md` |
| Landing page | AI Developer Agent builds a page | Codex maintains `03_Website/public/index.html`, CSS, JS, and PHP capture |
| Email capture database | GenSpark database | Starter PHP CSV capture in `03_Website/public/storage/`; later email platform integration |
| Lead magnet | Interactive app generated in GenSpark | `03_Website/public/lead-magnet/index.html` and `04_Lead_Magnets/7-day-reset/` |
| Email sequence | GenSpark writes emails | Drafts in `05_Content/email/sequence_draft.md` |
| Pinterest pins | AI Designer Agent with Nano Banana | Prompt bank in `05_Content/pinterest/pin_prompt_bank.md`; images can be created manually or in Google Flow |
| Publish | GenSpark custom domain publish | FTP/FTPS deploy helper in `07_Deploy/tools/publish-ftp-files.ps1` |
| Automations | GenSpark workflows/Claw | Later Codex automations, Make.com/Zapier, email platform automations, and content calendars |

## Practical Build Order

1. Launch a clean opt-in landing page.
2. Gate the interactive 7-day reset behind the form.
3. Apply to Mindvalley/Impact and prepare fallback offers.
4. Publish 15 to 30 Pinterest pins that all point to the opt-in page.
5. Move follow-up email delivery into a proper email platform before meaningful traffic.
6. Track click paths with UTM parameters and a simple affiliate-link map.

