# Manifested Fit Project Instructions

Manifested Fit is an affiliate funnel and content project for `manifestedfit.com`.

## Startup

1. Read this file first.
2. Read `Brain/README.md`, then the current-state files it points to.
3. Treat `Archive/` as read-only reference unless the user explicitly asks to revive or move something from it.
4. Verify whether the project has been initialized as a Git repo before using Git assumptions.

## Working Rules

- Keep secrets out of tracked files. FTP, email platform, API, and affiliate credentials belong in ignored local config files only.
- Include clear affiliate disclosure and health/wellness disclaimers on public pages and in launch materials.
- Do not make unsupported medical, fitness, income, or transformation claims.
- Prefer shared-hosting-friendly static HTML/CSS/JS plus small PHP endpoints until the project needs a heavier stack.
- Keep the funnel pieces organized as landing page, lead magnet, email sequence, traffic assets, deploy tooling, and memory.

## Deployment

- FTP/FTPS publishing lives in `07_Deploy/`.
- Deployment targets live in `07_Deploy/targets/`.
- Use `07_Deploy/targets/bluehost/config.json` for the Bluehost FTP credentials. That file should remain ignored.
- Use `07_Deploy/targets/local-preview/` for localhost preview notes and any future local-only runtime config.
- Prefer uploading explicit files or the full `03_Website/public` folder after local verification.

## Closeout

After meaningful work, update:

1. `Brain/CURRENT_STATE.md`
2. `Brain/SESSION_LOG.md`
3. `Brain/TASKS.md`
4. `NEXT_CONTEXT_BRIEF.md` when workflow or next steps change
