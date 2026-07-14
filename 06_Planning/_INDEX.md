# Planning Folder Index

Last scanned: 2026-07-14 (by Jeeves, against `Brain/CURRENT_STATE.md` and `NEXT_CONTEXT_BRIEF.md`).

**Read this file first.** Every doc below also carries a one-line `> **Status:**` banner right under its own title, so opening any single file tells you the same thing without coming back here. Re-scan and refresh this table whenever a doc's status materially changes — don't let it drift.

For the actual current build state and next actions, the authoritative docs are always `Brain/CURRENT_STATE.md` and `NEXT_CONTEXT_BRIEF.md` at the project root, not anything in this folder. This folder is strategy/reference/design material that feeds into those.

## Status legend

| Symbol | Meaning |
|---|---|
| 🟢 | Active — accurate, still the live plan/reference |
| 🟡 | Caution — partly stale, not started, or overlapping with something newer; read the banner before relying on it |
| 📦 | Archived — historical only, moved to `Archive/` |

## Read-first order (by task)

- **Starting affiliate work** → `AFFILIATE_APPLICATION_READINESS.md` → `AFFILIATE_STRATEGY.md` → `AFFILIATE_LINKS_GUIDE.md`
- **Working on the video pipeline** → `VIDEO_PIPELINE_PLAN.md` → `MUSIC_AND_OUTRO_BRIEF.md` → then `NEXT_CONTEXT_BRIEF.md` (project root) for what's actually done
- **Deciding on the YouTube channel / content strategy question** → `ACTION_PLAN_2026-07.md` (read the status banner first — it's a proposed direction, not what shipped)
- **Picking up general project state** → don't start here at all; go to `Brain/CURRENT_STATE.md` and `NEXT_CONTEXT_BRIEF.md`

## All files

| File | Status | Last updated | What it's for |
|---|---|---|---|
| `AFFILIATE_APPLICATION_READINESS.md` | 🟢 Active | 2026-05-03 | Evidence needed before applying to selective affiliate programs (e.g. Mindvalley); lower-barrier targets to hit first |
| `AFFILIATE_STRATEGY.md` | 🟢 Active | 2026-05-02 | Funnel design, offer criteria, compliance guardrails |
| `MUSIC_AND_OUTRO_BRIEF.md` | 🟢 Active | 2026-07-08 | Suno prompts + file drop locations for jingle/persona music/outros; live config reference for the video worker |
| `VIDEO_PIPELINE_PLAN.md` | 🟢 Active (design doc) | 2026-07-06 | The plan that was actually built for automated blog-companion videos. For current build state, defer to `NEXT_CONTEXT_BRIEF.md` |
| `AFFILIATE_LINKS_GUIDE.md` | 🟡 Active, incomplete | 2026-05-02 | How to add offers to the static site's `affiliate-offers.js`. Doesn't yet cover the newer in-post `manifested-fit-affiliates` WP plugin — two separate offer registries now exist |
| `LAUNCH_CHECKLIST.md` | 🟡 Partially done | 2026-05-01 | Pre-launch checklist. Site is live; analytics + email platform are the genuinely still-open items |
| `CONTENT_SYSTEM.md` | 🟡 Not started | 2026-05-01 | Manual Pinterest-led weekly content rhythm, predates the automated WP content engine that now runs content. Dormant, not superseded — revisit if Pinterest becomes a deliberate push |
| `HERMES_SOCIAL_AGENT_WORKFLOW_BRIEF.md` | 🟡 Future idea | 2026-05-03 | Proposed Hermes/AI Command Bridge agent team for social readiness. Never built; social work is flowing through the WP content engine + Telegram instead |
| `ACTION_PLAN_2026-07.md` | 🟡 Superseded direction | 2026-07-05 | Proposed Higgsfield-based two-lane standalone YouTube channel strategy. **Not** the path built (see `VIDEO_PIPELINE_PLAN.md`) — but §7 affiliate money plan and §8 YouTube safety note are still valid general reference |
| `reports/weekly-report-2026-07-05.md` | 🟢 Dated snapshot | 2026-07-05 | First (and so far only) weekly status report. A point-in-time record, not a living doc — fine as-is; add new dated reports here rather than editing this one |
| `Archive/2026-06-11 where we left off.txt` | 📦 Archived | 2026-06-11 | Superseded status snapshot. Use `Brain/CURRENT_STATE.md` / `NEXT_CONTEXT_BRIEF.md` instead |
| `Archive/VIDEO_WORKFLOW_MAP.md` | 📦 Archived | 2026-05-01 | One-time GenSpark-to-Codex concept mapping from project inception. Historical only |

## Maintenance note

When you finish work that changes one of these docs' relevance (a plan gets built, a checklist item closes, a doc goes fully stale), update that file's status banner **and** this table in the same pass — don't let them diverge. If a doc becomes fully dead, move it to `Archive/` with a banner explaining why, the same pattern used above.
