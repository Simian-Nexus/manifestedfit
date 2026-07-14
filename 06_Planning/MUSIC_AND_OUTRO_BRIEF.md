# Music, Jingle & Outro Brief

Last updated: 2026-07-08. Companion to `VIDEO_PIPELINE_PLAN.md`. This file exists so the music prompts and outro lines live in the repo, not in chat history.

## AI music generator options (for the jingle + persona background tracks)

| Service | Cost | Commercial/YouTube use | Notes |
|---|---|---|---|
| **Suno** | Free tier; Pro ~US$10/mo | **Paid tier only** — free-tier tracks are non-commercial | Best all-rounder for full songs + jingles with vocals. Easiest prompt workflow. |
| **Udio** | Free tier; paid ~US$10/mo | Paid tier for commercial rights | Suno's main rival; often better fidelity, slightly fiddlier. |
| **Stable Audio** (Stability AI) | Free tier; Pro ~US$12/mo | Paid tier commercial | Strong at *instrumental* ambient/electronic — good fit for calm wellness beds. |
| **Mubert** | Free w/ attribution; ~US$14/mo | Paid = royalty-free for YouTube | Generates endless ambient beds by mood/genre — purpose-built for background music, weaker at jingles. |
| **Soundraw** | ~US$17/mo | Yes, incl. monetized YouTube | Slider-based (mood/tempo/length), no prompt writing. Consistent but samey. |
| **AIVA** | Free tier; Pro ~€33/mo | Pro tier | Cinematic/orchestral leaning. Overkill here. |
| **MusicGen (Meta)** | **Free, runs locally on the RTX 3060** | Yes (open weights, your output) | Instrumental only, ~30s chunks. Zero cost, private, scriptable into the pipeline — viable for persona beds; not great for a polished jingle. |

**Recommendation:** Suno Pro for the one-off jingle (needs to be catchy, possibly with a sung "Manifested Fit" tag) + either Suno for the four persona beds too, or MusicGen locally if you want $0 and don't mind stitching. Buy one month of Suno Pro, batch-generate everything, cancel if done.

## File drop locations (worker picks them up automatically)

- Jingle: `07_Deploy/targets/video-worker/branding/jingle.mp3` → then click "Rebuild intro/endcard" in the dashboard so it bakes into `intro.mp4`.
- Persona beds: `07_Deploy/targets/video-worker/music/<Persona Name>/*.mp3` (multiple files fine; worker rotates). Folders already exist for all four personas (currently empty).

## Suno prompts

### Brand jingle (3–5s sting for the intro)
> Uplifting 4-second audio logo sting, warm acoustic guitar and soft synth pad, gentle rising motif ending on a bright resolved chord, subtle chime, calm wellness brand, no drums, no vocals, clean ending

Variant with vocal tag:
> 5-second jingle, soft female voice singing "Manifested Fit" over warm acoustic guitar and airy pads, gentle and serene, spa-like, resolved ending

### Persona background beds (2–3 min, instrumental, loopable, mixed low under voiceover)
- **Dana Cole (grounded & practical):** "Calm minimal lo-fi instrumental, steady soft beat, warm piano chords, unhurried, focused morning-routine energy, no vocals, loopable"
- **Nadia Brooks (warm & story-led):** "Gentle acoustic instrumental, fingerpicked guitar, soft strings, warm and intimate storytelling mood, slow tempo, no vocals, loopable"
- **Frankie Moon (playful & good-vibes):** "Light upbeat instrumental, ukulele and hand claps, sunny and playful, mid-tempo, feel-good wellness vibe, no vocals, loopable"
- **Rowan Ellis (calm & minimal):** "Ambient meditation instrumental, slow evolving synth pads, soft piano notes, spacious and serene, very minimal, no vocals, loopable"

## Per-persona spoken outros (Jonathan's spec)

Template: *"If you like this content, don't forget to like and ___ the subscribe button. See you next time."* — verb matched to the persona's voice. Set these in the dashboard (`http://localhost:8765`) under each persona's outro field; the worker already speaks the configured outro line over the brand end-card.

- **Dana Cole:** "If this helped, do me a favour — hit like and press the subscribe button. See you next time."
- **Nadia Brooks:** "If this resonated with you, leave a like and gently select the subscribe button. See you next time."
- **Frankie Moon:** "If you liked this, smash that like button and give subscribe a big friendly boop. See you next time!"
- **Rowan Ellis:** "If you found this useful, like the video and gently caress the subscribe button. See you next time."

(Check current `config.json` outros via the dashboard before overwriting — some may already be set from the 2026-07-08 session.)
