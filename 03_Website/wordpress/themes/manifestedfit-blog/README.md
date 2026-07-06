# Manifested Fit Blog — brand child theme

A block child theme of **Twenty Twenty-Five** that repaints the blog in the Manifested Fit brand: teal/green/plum palette, Inter typography, and matching buttons — pulled straight from the flat site's `styles.css` so `/blog` looks like part of `manifestedfit.com`.

## Install (via FileZilla, ~3 min)

1. In FileZilla, go to `/public_html/manifestedfit.com/blog/wp-content/themes/` on the server.
2. Upload the whole **`manifestedfit-blog`** folder (this folder) into `themes/`.
   *(Twenty Twenty-Five is already installed — it's the parent, leave it there.)*
3. In `wp-admin` → **Appearance → Themes** → hover **Manifested Fit Blog** → **Activate**.
4. **Clear SpeedyCache** (you have it active): SpeedyCache → Clear Cache. Otherwise you'll keep seeing the old look.
5. Refresh `manifestedfit.com/blog`. To see a real post styled, open a draft and hit **Preview**.

## One extra step for a pixel-match: install the Inter font

The flat site uses Inter. To load it on the blog:

- **Appearance → Editor → Styles** (the half-circle icon) → **Typography** → **Manage fonts / Font Library** → **Install Fonts** tab → search **Inter** → install the regular + bold weights.

The theme already references Inter; installing it makes it render identically to the funnel. Until then it falls back to your system sans-serif (still clean, just not identical).

## What it styles automatically

- Page background = Cream `#fbfaf4`, body text = Ink `#183833`
- Links + buttons = Teal `#08736f` (hover `#065d59`), 8px radius, brand shadow
- Headings = bold, tight leading, ink
- Quote blocks get a green left-accent; pullquotes go plum
- Embedded YouTube is full-width with rounded corners
- Palette (Teal/Green/Plum/Leaf/etc.) appears as brand color swatches in every block's color picker

## Not included on purpose

- Header/footer nav still shows Twenty Twenty-Five's placeholder links (Events, Shop, Patterns…). Edit those in **Appearance → Editor → Patterns / Templates** — a separate quick task.
- Logo: add via **Appearance → Editor → Header** → replace the site title with your logo image if you want.

## Files

- `theme.json` — the brand system (colors, fonts, element styles). The important one.
- `style.css` — small extras theme.json can't express (button shadow, outline button, helper classes) + theme header.
- `functions.php` — enqueues `style.css` on the front end and in the editor.
