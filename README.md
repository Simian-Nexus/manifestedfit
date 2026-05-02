# Manifested Fit

Manifested Fit is a wellness and mind-body affiliate project for `manifestedfit.com`.

The first build path is intentionally simple:

- a conversion landing page
- an interactive lead magnet
- email follow-up drafts
- Pinterest/Nano Banana prompt banks
- FTP/FTPS deploy tooling for shared hosting
- project memory so future Codex sessions can resume cleanly

## Current Pieces

- Website source: `03_Website/public/`
- Lead magnet concept: `04_Lead_Magnets/7-day-reset/`
- Strategy and launch docs: `06_Planning/`
- Pinterest and email content drafts: `05_Content/`
- FTP publish helper: `07_Deploy/tools/publish-ftp-files.ps1`
- Project memory: `Brain/`

## Local Preview

The static pages can be opened directly from `03_Website/public/index.html`.

For the PHP opt-in endpoint, run a local PHP server from the project root:

```powershell
php -S 127.0.0.1:8098 -t 03_Website/public
```

Then visit `http://127.0.0.1:8098/`.

## Git

This folder is not initialized as a Git repo yet. When ready:

```powershell
git init
git add .
git commit -m "Initial Manifested Fit funnel scaffold"
```

## Deployment

Copy `07_Deploy/config/ftp-publish.local.example.json` to `07_Deploy/config/ftp-publish.local.json`, add the FTP/FTPS credentials, then run:

```powershell
.\07_Deploy\tools\publish-ftp-files.ps1 -All
```

Keep the real local config file out of Git.

