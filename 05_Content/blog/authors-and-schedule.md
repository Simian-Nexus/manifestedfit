# Manifested Fit — Columnists & Posting Voice Schedule

Config spec for the blog's rotating "voice team." Feeds the future content-engine plugin.

## The columnists (voice personas)

Persona bylines, not fabricated experts. Keep bios vibe-based; never invent credentials or use AI headshots-as-real-people. Add an honest "About our voices" note on the blog.

| Byline | Voice | Vibe |
|---|---|---|
| **Dana Cole** | Grounded & practical | Direct, warm, no-fluff. "Here's the real reason." |
| **Nadia Brooks** | Warm & story-led | Empathetic, a little vulnerable, starts with a story. |
| **Frankie Moon** | Playful & curious | Witty, light, good vibes. Makes wellness fun. |
| **Rowan Ellis** | Calm & minimal | Serene, spare, meditative. Fewer words, more space. |

## Weekly schedule (voice matched to the day's prevailing mood)

- **Monday — Frankie (Playful).** Everyone dreads Monday. Lead with good vibes and humour to defuse the dread.
- **Tuesday — Dana (Grounded).** The real "get to work" day. Practical, momentum-building.
- **Wednesday — Nadia (Warm).** Hump-day slump. Encouragement and connection: you're not alone.
- **Thursday — Dana (Grounded).** Keep the momentum, practical push toward the finish.
- **Friday — Rowan (Calm).** Wind down. Let the week be enough; ease toward rest.
- **Saturday — random** (any of the four, chosen at generation time).
- **Sunday — random** (any of the four, chosen at generation time).

## Canadian holiday override

On Canadian statutory holidays, **Frankie (Playful)** posts regardless of weekday — a happier, celebratory vibe.

**2026 dates:** New Year's Day (Jan 1), Family Day (Feb 16), Good Friday (Apr 3), Easter Monday (Apr 6), Victoria Day (May 18), Canada Day (Jul 1), Civic Holiday (Aug 3), Labour Day (Sep 7), Thanksgiving (Oct 12), Christmas (Dec 25), Boxing Day (Dec 26).

### Solemn-day exceptions — do NOT use Frankie's whimsy
Two "holidays" are days of mourning/remembrance where a playful tone would read as tone-deaf:
- **National Day for Truth and Reconciliation (Sep 30)** — commemorates residential-school victims.
- **Remembrance Day (Nov 11)** — honours those who died in war.

On these, either **skip the post** or publish a short, respectful piece in **Rowan's calm voice** (no product CTA, no jokes). Flagged so the automation never blasts a "good vibes" post on those dates.

## Notes for implementation
- Weekend "random" = uniform random pick across the four voices.
- Set each post's WordPress author to the matching persona user (see persona-users task).
- Provider (Claude/OpenAI/Gemini) is chosen separately from voice — voice = *how* it reads, provider = *what generates it*.
