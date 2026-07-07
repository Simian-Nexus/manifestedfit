# Automated Video Pipeline Plan (2026-07-06)

Goal: every daily blog draft gets a 60–90s faceless YouTube companion video,
generated automatically, approved by Jonathan in Telegram, uploaded to the
Manifested Fit YouTube channel, and embedded into the draft — with Telegram
notifications at every step.

## What already exists (plugin v0.2.0, done)

The WordPress plugin now handles the entire *blog side* of the video flow:

- Every non-solemn draft is created with a `<p>[VIDEO EMBED]</p>` placeholder
  and an AI-written **video brief** (post meta `mfce_video_brief`):
  `youtube_title`, `youtube_description` (contains a literal `{POST_URL}`
  placeholder — substitute the real permalink at upload time),
  `voiceover_script` (150–220 spoken words in the persona's voice), and
  `visual_direction` (shot-by-shot b-roll / overlay guidance).
- REST API for the external worker (secret = the cron secret, exact URLs on
  the plugin admin page):
  - `GET  /wp-json/mfce/v1/video-queue?secret=X` → posts needing video, with
    briefs, permalink and status (`needed | review | approved | rejected`).
  - `POST /wp-json/mfce/v1/video-ready?secret=X&post_id=N&preview_url=URL`
    → Telegram "video ready" message with Approve/Reject buttons.
  - `POST /wp-json/mfce/v1/video-embed?secret=X&post_id=N&youtube_url=URL`
    → placeholder swapped for a responsive YouTube embed + Telegram
    "video embedded, review & publish" notice.
- All Telegram messaging (ready / approved / rejected / embedded) is sent by
  the plugin — the worker never needs the bot token.

## What the external worker must do (to build)

A loop, run 1–2× daily *after* the 6 AM blog cron (e.g. 7 AM and 5 PM):

1. `GET video-queue`.
2. For each post with status `needed` (or `rejected` → regenerate):
   a. Build the video from the brief: voiceover audio + visuals + captions.
   b. Upload to YouTube as **unlisted** (title/description from the brief,
      `{POST_URL}` replaced with the post permalink).
   c. `POST video-ready` with that unlisted URL → Jonathan gets the
      Approve/Reject buttons in Telegram.
3. For each post with status `approved`:
   a. Flip the YouTube video to **public** (videos.update, privacyStatus).
   b. `POST video-embed` with the YouTube URL → plugin embeds + notifies.

Rejected videos stay in the queue as `rejected`; the worker regenerates
(optionally with a different seed/stock set) and goes through `video-ready`
again.

## Where the worker runs — options

### Option A (recommended): local Python worker on Jonathan's PC
- Windows Task Scheduler (or a Claude Code scheduled routine) runs
  `video_worker.py` twice a day.
- **Cost: $0.** Full control, no per-render fees, easy to debug.
- Downside: PC must be on; a missed day just means the video arrives a day
  late (the blog draft is independent and unaffected).

### Option B: Make.com scenario
- Modules: HTTP (queue poll) → video renderer (JSON2Video / Creatomate /
  Shotstack — all **paid**, roughly $0.5–2 per rendered minute at low tiers)
  → YouTube upload module → HTTP (video-ready / video-embed).
- Pros: no PC dependency. Cons: monthly cost, per-render cost, fiddly to
  express "wait for Telegram approval" (solved by polling `video-queue` on a
  schedule, since approval status is exposed there).

### Option C: full AI-generated video (per-second APIs, researched 2026-07-06)
Prices move fast - verify before subscribing. Roughly, for a 75s daily video:

| Provider / model | ~Price | 75s/day cost | Notes |
|---|---|---|---|
| Kling (3.0 / O3) | ~$0.07/s | ~$5.25/day (~$160/mo) | Price-performance leader for volume |
| Seedance 2.0 Fast | ~$0.09/s | ~$6.75/day | Cheapest production-quality 1080p |
| Google Veo 3.1 | via Gemini AI Pro $19.99/mo (Fast tier) or API per-second | varies | Includes audio; Ultra plan $249.99/mo |
| Sora 2 | ~$0.10/s | ~$7.50/day | |
| HeyGen (avatars) | subscription | ~$29+/mo | Talking-head style, not b-roll |

### Option D: faceless-video SaaS (script -> stock video + TTS, like Option A but hosted)
- GenFaceless ~$20/mo (30 videos), InVideo, Pictory, Crayo, FluxNote.
- Closest hosted equivalent to Option A; check API availability before
  committing (many are UI-only, which breaks full automation).

Recommendation unchanged: start with Option A ($0, fully automatable),
graduate to Kling/Veo per-second APIs for hero content once revenue exists.

## Video assembly recipe for Option A (all free)

1. **Voiceover**: `edge-tts` (Microsoft neural voices, free, no key) — one
   voice per persona for consistency. Alternative: Kokoro TTS locally.
2. **Visuals**: Pexels/Pixabay API (free keys) — search using the
   `visual_direction` keywords, download 5–8 vertical-safe clips.
3. **Assembly**: `ffmpeg` — concat clips to voiceover length, overlay
   on-screen text from `visual_direction`, burn simple captions
   (whisper-timestamped or just the script split by sentence), 1080p 16:9,
   brand end-card with manifestedfit.com.
4. **Upload**: YouTube Data API v3 (`videos.insert`, `videos.update`).
   One-time setup: Google Cloud project → enable YouTube Data API →
   OAuth desktop credentials → run once interactively to store the refresh
   token. Note: new/unverified API projects upload as **private/locked**
   until the app passes a (quick, free) audit — start it early; unlisted
   previews can meanwhile be watched from the owning account.

Secrets (YouTube OAuth json, Pexels key, MFCE cron secret) live in an
ignored local config per studio rules — suggested spot:
`07_Deploy/targets/video-worker/config.json` (gitignored).

## Telegram touchpoints (already wired)

1. Draft created → notice + Publish/Keep/Trash (existing).
2. Video rendered → "Video ready for review" + preview link +
   **Approve video / Reject video** (new).
3. Video embedded → "Video is now embedded in the draft" + Publish buttons
   (new). Jonathan reads, then publishes via button or replies with
   revision instructions (existing revise-by-reply, now video-safe).

## Next actions

- [ ] Re-upload plugin v0.2.0 to Bluehost and re-test Run Now (verify the
      draft contains `[VIDEO EMBED]` and the brief meta).
- [ ] Decide Option A vs B (recommendation: A).
- [ ] Google Cloud project + YouTube OAuth credentials; start API audit.
- [ ] Get a Pexels (and/or Pixabay) API key.
- [ ] Build `video_worker.py` (queue poll → edge-tts → ffmpeg → YouTube
      unlisted → video-ready; approved → public → video-embed).
- [ ] Schedule it (Task Scheduler, 7 AM + 5 PM).
- [ ] End-to-end dry run on one existing draft.
