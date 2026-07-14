# Launch Checklist

> **Status:** 🟡 Partially done. The site is live and most pre-launch items are complete. Genuinely still open: analytics (GA4/Search Console — per `NEXT_CONTEXT_BRIEF.md`, due the week of 2026-07-13) and a production email platform to replace the CSV bridge. Content-launch and email-launch sections below are unstarted and now overlap with newer docs (`CONTENT_SYSTEM.md`, `AFFILIATE_APPLICATION_READINESS.md`).

## Before Public Traffic

- [ ] Add real FTP/FTPS credentials to `07_Deploy/targets/prod/config.json`.
- [ ] Run the local PHP server and test the opt-in flow.
- [ ] Confirm leads are captured and `storage/leads.csv` is not publicly readable.
- [ ] Replace placeholder affiliate links after approval.
- [ ] Add Google Analytics, Plausible, or another analytics tool.
- [ ] Confirm the domain points to the correct hosting root.
- [ ] Publish privacy policy and affiliate disclosure.
- [ ] Confirm all forms work on mobile.

## Content Launch

- [ ] Create 15 launch Pinterest pins.
- [ ] Save final art in `Media/`.
- [ ] Write 5 pin descriptions per content angle.
- [ ] Schedule pins over 2 to 3 weeks.
- [ ] Create 3 short-form video scripts from the same content angles.

## Email Launch

- [ ] Choose the email platform.
- [ ] Move the draft welcome sequence into that platform.
- [ ] Add unsubscribe and sender details.
- [ ] Test the lead magnet delivery email.
- [ ] Add a manual export path if using the starter CSV capture temporarily.
