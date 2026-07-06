# Manifested Fit Content Engine (WordPress plugin)

Supervised AI drafting engine for manifestedfit.com/blog. Once a day (Bluehost cron)
it picks the day's persona, takes the next topic from the queue, asks Claude to write
the post, saves it as a **draft** under the persona's byline, and sends a Telegram
notification with the review link. **It never publishes anything.**

## What's baked in

- Persona rotation: Mon Frankie, Tue Dana, Wed Nadia, Thu Dana, Fri Rowan, weekends random.
- Canadian statutory holidays (2026) -> Frankie with a celebratory angle.
- Solemn days (Sep 30, Nov 11) -> short respectful Rowan reflection, no CTA, no queue topic consumed.
- Personas resolved by WP login name (DanaCole, FrankieMoon, NadiaBrooks, RowanEllis) - no user IDs needed.
- Categories assigned by name from the fixed six (created if missing).
- RankMath focus keyword set on each draft (`rank_math_focus_keyword` meta).
- Structured JSON output from the Claude API - no fragile text parsing.
- At most one draft per calendar day from cron (Run Now can force extras).
- Failures (empty queue, API error, missing key) are Telegram-notified so silence means "nothing to do".

## Install

1. Upload the `manifested-fit-content-engine` folder to
   `/wp-content/plugins/` on the blog (FileZilla, same as the theme).
2. Activate it in wp-admin -> Plugins.
3. Open the new **Content Engine** menu item and fill in:
   - Anthropic API key (create at console.anthropic.com - the paid metered API).
   - Telegram bot token + chat id (from `07_Deploy/targets/telegram/config.json`).
4. Click **Send Telegram test** - your phone should buzz.
5. Add a few topics to the queue (one per line; optional `| editor notes`).
6. Click **Run Now** - after a minute or two a draft appears in Posts and
   Telegram pings you with the edit link.
7. Tick **Enabled** and save, then set up the cron (below).

## Two-way Telegram (approve / revise / chat)

Click **Enable two-way Telegram (register webhook)** on the plugin admin page.
This points the bot's webhook at `/wp-json/mfce/v1/telegram` (guarded by a
random secret that Telegram echoes back in a header). From then on:

- Draft notifications carry **Publish / Keep draft / Trash** buttons. Tapping
  **Publish** is the human approval step - the engine itself still never
  publishes anything.
- **Replying** to a draft notification with text (e.g. "shorter intro, add a
  bullet list") sends those instructions to the configured model, which
  revises the draft and re-notifies you with fresh buttons.
- Any **other message** to the bot gets a plain AI answer from the configured
  model - a mini chat assistant in your pocket.

Only the configured chat id can drive any of this; messages from anyone else
are ignored. Note: once the webhook is registered, `getUpdates` polling (used
during initial local setup) stops working - that's expected.

### Which AI answers?

Whatever the **AI provider** dropdown in the plugin settings says - it drives
post generation, draft revision, and Telegram chat alike:

- **Anthropic (Claude)** - metered API key from console.anthropic.com, model
  default `claude-opus-4-8`. A claude.ai subscription cannot be wired in;
  subscriptions only cover Anthropic's own apps, not server-side API calls.
- **Google Gemini** - API key from aistudio.google.com, model default
  `gemini-2.5-flash`. Works on the free tier (rate limits are irrelevant at
  one post/day), but note Google may use free-tier API content to improve its
  products - fine for blog drafts, don't send anything private through it.
- **OpenAI** - key from platform.openai.com, model default `gpt-5.1`.
- **Grok (xAI)** - key from console.x.ai, model default `grok-4`.
- **Custom / local (OpenAI-compatible)** - any endpoint that speaks the
  chat-completions API: Ollama (`http://host:11434/v1`), LM Studio, vLLM,
  LiteLLM, etc. API key optional. **The URL must be reachable from the
  Bluehost server**, not from your house - a home PC running Ollama needs a
  tunnel (Tailscale Funnel, cloudflared, ngrok) or the VM a public IP, and it
  should be HTTPS or a private tunnel, never a bare public HTTP port.

All providers share the same structured-JSON pipeline. OpenAI and Grok use
strict `json_schema` mode; the custom provider degrades gracefully
(`json_schema` -> `json_object` -> prompt-enforced JSON with code-fence
stripping) so smaller local models still produce parseable drafts. Model
name fields are free text, so new models need no plugin update.

## Bluehost cron

cPanel -> Cron Jobs -> add a daily job (e.g. 6:00 AM). The plugin admin page
shows the exact command, which looks like:

    curl -s "https://manifestedfit.com/blog/wp-json/mfce/v1/run?secret=XXXX" > /dev/null 2>&1

If that ever times out on shared hosting (the AI call can take 1-3 minutes),
switch to the CLI runner instead - no web-server timeout:

    php -q /home2/USERNAME/public_html/blog/wp-content/plugins/manifested-fit-content-engine/cron-runner.php XXXX

(`XXXX` = the cron secret from the admin page; adjust the path to your account.)

## Cost

Default model is `claude-opus-4-8` ($5/$25 per million tokens). A daily post is
roughly 1k input + 2.5k output tokens, i.e. about $0.07/post or ~$2/month.
Swap the model in settings if you want cheaper (e.g. `claude-sonnet-5`).

## Safety posture

- Drafts only - `post_status` is hard-coded to `draft`.
- The API key is stored in WP options on the server and never leaves it (password
  field in the admin never echoes it back).
- The cron endpoint is guarded by a random 32-char secret (constant-time compare).
- Persona guardrails are in the system prompt: no fabricated credentials, no
  medical/income claims, honest voice-byline framing.
