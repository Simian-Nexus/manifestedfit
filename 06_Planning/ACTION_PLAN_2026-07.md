# Manifested Fit — YouTube & Affiliate Action Plan

> **Status:** 🟡 Superseded direction, partly still useful — see `_INDEX.md`. The Higgsfield/Lane A+B standalone YouTube-channel plan proposed here was **not** the path actually built; the project instead built automated blog-companion videos (see `VIDEO_PIPELINE_PLAN.md`, now live). §7 (affiliate money plan) and §8 (YouTube safety note) remain valid general reference. Don't treat the 30-day sprint or tool stack (§4, §9) as current marching orders.

Last updated: 2026-07-05
Owner: Jonathan
Goal: Two income streams from a faceless AI wellness/spiritual channel — (1) YouTube ad revenue from views/engagement, and (2) affiliate commissions — with Claude running a supervised, scheduled content engine and reporting on what to do / what was done.

---

## 1. Where things stand (honest audit)

What already exists and is genuinely useful:

- A real brand: Manifested Fit, positioned around manifestation + gentle fitness + mindset, with a written brand brief, voice, and colour system (`02_Strategy/BRAND_BRIEF.md`).
- A live website at `manifestedfit.com` with a landing page, an interactive 7-day reset lead magnet, a thank-you page, a `/resources/` affiliate hub, and legal/disclosure placeholders (`03_Website/public/`).
- A working (if basic) email capture that writes leads to CSV, plus FTP/FTPS deploy tooling (`07_Deploy/tools/publish-ftp-files.ps1`).
- An affiliate offer registry ready to be populated (`03_Website/public/assets/js/affiliate-offers.js`).
- A strong planning layer: affiliate strategy, application-readiness notes, a content system, and a supervised "Hermes" agent-team concept (`06_Planning/`).
- A YouTube channel with an AI-generated logo: https://www.youtube.com/@ManifestedFit — but effectively no published videos yet.
- An archive of 30+ earlier wellness scripts and some rough AI videos/voiceovers (`Archive/Pre_2026-05-01/`) — reusable raw material.

The one thing missing is the thing that actually makes money: **published video volume and audience.** Everything else is scaffolding waiting for content to flow through it. So this plan is deliberately biased toward shipping videos fast.

---

## 2. The creator's workflow, decoded (from the transcript)

Matt Parr's "20-minute channel" (Apex Psychology reference, ~1.4M views/mo) is a repeatable loop, not magic:

1. **Niche** → pick a high-RPM faceless niche (he used psychology, ~$7–15 per 1,000 views).
2. **Branding** → Claude generates channel names → check availability on YouTube → Claude makes logo/banner (GPT Image) and a stylized 2D character as the channel's "face"/IP.
3. **Idea mining** → find *outlier* videos (big views relative to a small channel) → screenshot them → Claude generates 30+ **combination** ideas (merge two proven topics into something new).
4. **Scripting** → feed 2–3 high-performing videos on the topic as "training data" → generate a retention-optimised 10-min script with hook options.
5. **Voice** → AI voiceover (Higgsfield voice / ElevenLabs).
6. **Visuals** → Claude turns the script into shot prompts (new scene every ~5s) referencing a consistent character → generate clips in Higgsfield (Seedance/Kling) → stitch in CapCut, *or* let Claude Code + ffmpeg auto-assemble the whole video.
7. **Money** → Claude finds an affiliate product paying $50–150/sale (his example: online-therapy.com, $150/signup, 90-day cookie) → link in every description from day one → build an own digital product later.

The important insight: the character is stylized/animated **on purpose** because it's forgiving. That directly informs the style decision below.

---

## 3. Style decision — your core question, answered

You asked how realistic a consistent AI narrator doing tai chi / chopping cilantro can be, vs cartoony, and what's the fastest path to genuinely interesting, potentially viral wellness+spiritual videos.

Straight answer on current (mid-2026) tool reality:

- **Realistic humans doing specific physical actions** (a tai chi flow, hands chopping cilantro, a yoga transition) is the *hardest* thing for AI video today. Hands, food, and continuous body motion are exactly where models morph and break, and keeping the *same* realistic narrator consistent across 30+ clips is still imperfect even with Seedance 2.0's multi-reference feature. Beautiful for a few hero shots; slow, costly, and fragile to automate at volume. Treat it as a garnish, not the engine.
- **Stylized/cartoony character** is far more forgiving, cheaper, faster, and builds ownable IP — which is why the psychology channel uses it.
- **Faceless voice-led** (a strong AI narrator over atmospheric visuals + music, no recurring character at all) is the fastest and most automatable of the three, and it happens to *be* the dominant, proven format in your exact niche — guided meditation, manifestation, self-hypnosis, sleep, affirmations. Retention comes from script + voice + music, so there's no character-consistency problem to solve. Ambient/meditation content runs about $10–11 RPM.

### Recommendation: run two lanes as an A/B, lead with voice-led

- **Lane A — Manifested Fit (voice-led, primary).** Cinematic wellness/spiritual videos: manifestation, self-hypnosis, mindset, guided visualization, "sleep manifestation," morning-reset rituals. AI narrator + slow atmospheric visuals (AI dreamscapes + stock nature b-roll) + music. Fastest to volume, cheapest, and directly feeds the existing affiliate funnel and lead magnet. This is your workhorse and your money channel.
- **Lane B — a second, character-IP channel (test).** A stylized 2D "guide" character presenting the psychology-of-mindset / manifestation angle (Apex-style). Tests whether an ownable character accelerates growth and IP. More production steps per video, but higher ceiling for a recognisable brand.

Ship both for 30 days, measure *time-to-produce* and *views/retention per hour of effort*, then pour resources into whichever wins. My prediction: Lane A ships 3–5× faster and monetizes sooner; Lane B may grow a more loyal subscriber base if a clip lands. Let the data decide rather than guessing now.

Realistic-human tai-chi/cooking clips: keep them as occasional 5–8s hero shots inside Lane A videos (generated one at a time, hand-picked), not as a full channel format yet.

---

## 4. Recommended tool stack (lean, my call on budget)

Start with one paid subscription and scale only when a channel earns it.

- **Video + voice:** Higgsfield **Starter (~$15/mo)** — one workspace for Seedance 2.0, Kling 3.0, Veo 3.1, Sora, plus voiceover, and it connects into Claude as a custom connector exactly like the video shows. Best value entry point. Kling 3.0 is the cheapest per clip and strong for stylized multi-shot (Lane B); Seedance/Veo for realistic hero shots.
- **Narration (alternative/complement):** ElevenLabs — you already used its Jessica/Alice voices in the archive; a free/starter tier is fine to start. Pick ONE signature narrator voice per channel and keep it consistent — the voice *is* the brand in Lane A.
- **Idea + script research:** **Claude (me), not TubeMagic, to start.** I can do niche scoring, outlier-style idea mining, combination ideation, and retention-framework scripting for free within our sessions. Revisit TubeMagic (~paid) only once a channel is scaling and you want faster outlier data.
- **Editing:** CapCut (free) for hands-on control, or Claude Code + ffmpeg for hands-off auto-assembly (as in the transcript). Lane A voice-led videos are the easiest to auto-assemble.
- **Blog:** your existing `manifestedfit.com` — add a `/blog/` section with embedded YouTube, deployed via the FTP tooling you already have.
- **Thumbnails:** GPT Image / Higgsfield from Claude-written thumbnail briefs.

Estimated starting spend: **~$15–30/mo.** Everything else uses tools you already own.

---

## 5. The repeatable video pipeline (per video)

This is the loop the scheduled Claude task will run. Roughly 45–60 min of your time per Lane A video once dialled in.

1. **Idea** — Claude picks the next title from a maintained outlier/combination idea bank.
2. **Script** — Claude writes a retention-structured script (hook → open loops → payoff → CTA) in Manifested Fit brand voice, with 2–3 hook options.
3. **Voiceover** — generate narration in your signature voice (Higgsfield/ElevenLabs).
4. **Shot list** — Claude converts the script into visual prompts, new scene ~every 5s; Lane B prompts reference the IP character.
5. **Clips** — generate in Higgsfield (Kling for Lane B stylized, Seedance/Veo for Lane A realistic/atmospheric).
6. **Assemble** — CapCut or Claude Code + ffmpeg; add music, captions.
7. **Package** — Claude writes title, description (with affiliate + lead-magnet links), tags, pinned comment, and a thumbnail brief.
8. **Publish** — you review and upload (kept behind your approval, per your safety posture).
9. **Repurpose** — cut 2–3 Shorts from the long video; auto-draft the companion blog post (Section 6).

---

## 6. Blog + YouTube double-dip (per project instructions)

Every published video becomes an SEO blog post on manifestedfit.com:

- Claude drafts a 700–1,200-word wellness/spiritual article on the video's topic.
- Embed the YouTube video near the top (drives watch-time + is a monetization signal for the channel).
- Insert the free 7-day reset opt-in and one contextual affiliate CTA with disclosure.
- Deploy via the existing FTP helper.

Result: one script fuels a long video (ad revenue) + Shorts (reach) + a blog post (SEO + affiliate + email capture). This is the compounding loop the whole system is built to run.

---

## 7. Affiliate money plan

Do the "day one" version and the "real business" version in parallel, exactly as the transcript suggests:

- **Now:** apply to 2–3 lower-barrier wellness/mindset programs and put links in every video description + `/resources/` from the first upload. Strong fits for this niche: online-therapy.com (the transcript's example — ~$150/signup, 90-day cookie; vet it first), meditation/self-hypnosis apps, journals/planners, and personal-growth course marketplaces. Populate `03_Website/public/assets/js/affiliate-offers.js` as each is approved.
- **Aspirational:** Mindvalley (30% commission, via Impact) — but its stated ~75,000-follower bar means it waits until you have real traction. Don't gate launch on it.
- **Own product (highest margin):** once you see which topics land, package a guided self-hypnosis/manifestation audio series or a "21-day Manifested Fit reset" as a $27–97 digital product. This is where faceless wellness channels make the real money, beyond ad RPM.

Compliance stays as your docs already specify: clear affiliate disclosure, no medical/income guarantees, educational framing.

---

## 8. YouTube safety note (important for automation)

YouTube's mid-2025 "inauthentic content" policy targets mass-produced, repetitive, low-effort AI spam — **not** AI-assisted creation. AI-tool videos remain monetizable when they show original creative input. Practical guardrails: original scripts (not templated re-uploads), a consistent brand voice and visual identity, genuine value per video, and variety. The two-lane + IP-character approach and human-approval step all help keep you on the right side of this.

---

## 9. 30-day sprint (concrete)

**Week 1 — Foundations & first ships**
- Finalise channel identity for both lanes (names/handles, banners, character for Lane B). Confirm @ManifestedFit as Lane A.
- Set up Higgsfield Starter; pick and lock one narrator voice per lane.
- Claude builds the initial outlier/combination idea bank (30+ titles per lane).
- Produce and publish 2 Lane A videos + 1 Lane B video. Cut 3 Shorts.
- Publish the first companion blog post with embed.
- Apply to 2 lower-barrier affiliate programs; add links to descriptions.

**Week 2 — Rhythm**
- 3 Lane A + 1 Lane B long videos; 4–6 Shorts; 2 blog posts.
- Add analytics/UTMs to the site; connect an email platform (MailerLite/Beehiiv/ConvertKit) to replace the CSV bridge.
- First weekly Claude report (see Section 10).

**Week 3 — Measure & lean in**
- Same cadence; compare Lane A vs Lane B on time-to-produce and retention/views.
- Approve affiliate offers into `affiliate-offers.js`.
- Start outlining the own-product (self-hypnosis/manifestation series).

**Week 4 — Decide & systematise**
- Double down on the winning lane; scale its cadence.
- Prepare a simple media kit for later selective-affiliate applications.
- Review the month with Claude; set Month-2 targets.

Target by day 30: ~10–14 long videos, ~15+ Shorts, 6–8 blog posts, 2–3 affiliate programs live, email automation connected.

---

## 10. Scheduled Claude engine (the automation you asked for)

Two supervised recurring tasks (drafts for your approval — nothing publishes itself):

- **Weekly content engine (e.g. Monday AM):** refresh the outlier/idea bank, draft the week's scripts + shot prompts + titles/descriptions + thumbnail briefs + companion blog drafts, and queue them in the project for review.
- **Weekly status report (e.g. Friday PM):** "what to do next week / what was done this week" — videos shipped, ideas queued, affiliate/setup TODOs, and any blockers, written to `06_Planning/`.

A first version of the status report task is being set up now; the content-engine task can be switched on once you confirm the two channel names and the Higgsfield account.

---

## 11. Immediate next actions (this week)

1. Confirm the two channel names/handles (Lane A = @ManifestedFit; propose a Lane B name — I can generate and availability-check options).
2. Create the Higgsfield account (Starter) and connect it in Claude.
3. Let me build the first 30-title idea bank + write the first 3 scripts.
4. Approve the first affiliate program to link from day one.
5. Pick the email platform to replace the CSV capture.

Tell me which of these to start on and I'll run it.
