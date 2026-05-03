# Next Context Brief

## Current State

Manifested Fit now has a clean project scaffold for an affiliate funnel on `manifestedfit.com`.

The local project is a Git repository on `main`, tracking `origin/main` at `https://github.com/Simian-Nexus/manifestedfit.git`.

The original material has been preserved in `Archive/Pre_2026-05-01/`, including old video/content assets, a previous URL shortener experiment, and a previous WordPress snapshot that also exists under the broader `Spinning_Monkey_Web` archive.

The working website lives in `03_Website/public/` and currently includes:

- a landing page at `index.html`
- an interactive lead magnet at `lead-magnet/index.html`
- a thank-you/access page at `thank-you/index.html`
- a resources/affiliate hub at `resources/index.html`
- a public offer registry at `assets/js/affiliate-offers.js`
- legal placeholders for affiliate disclosure and privacy
- a small PHP opt-in collector at `api/collect-lead.php`
- private lead storage protected by `storage/.htaccess`

The deployment baseline mirrors the newer Spinning Monkey FTP publish-helper pattern: FTP credentials stay in ignored JSON config, publishing uses `curl.exe`, and uploaded files are hash-verified after transfer.

Mindvalley's official affiliate page says the application runs through Impact, the dashboard is hosted on Impact, the baseline commission is 30%, and the cookie lifetime is 30 days. It also says they look for a large established audience, so Mindvalley should be treated as the aspirational target while the site builds list and traffic.

Verification on 2026-05-01: `collect-lead.php` passes `php -l`, the FTP publish helper parses as PowerShell, the local PHP server returned 200 for the main pages, and a test POST returned a thank-you redirect JSON response. The fake test lead CSV was removed after verification.

Production publish note: uploading through the ignored prod config succeeds when the FTPS host is the certificate-matching server host used by sibling projects. As of 2026-05-02, the public domain is serving the site and `/resources/` returns the new resources hub.

## Important Paths

- Website root: `03_Website/public/`
- Deploy script: `07_Deploy/tools/publish-ftp-files.ps1`
- Production deploy target: `07_Deploy/targets/prod/`
- Local preview target: `07_Deploy/targets/local-preview/`
- Strategy map: `06_Planning/VIDEO_WORKFLOW_MAP.md`
- Affiliate strategy: `06_Planning/AFFILIATE_STRATEGY.md`
- Launch checklist: `06_Planning/LAUNCH_CHECKLIST.md`
- Memory index: `Brain/README.md`

## Next Likely Steps

1. Replace the placeholder affiliate offers in `03_Website/public/assets/js/affiliate-offers.js` as accounts are approved.
2. Replace the primary offer slug in `03_Website/public/assets/js/config.js` if another offer becomes the main recommendation.
3. Choose an email platform if long-term follow-up should move out of local CSV capture.
4. Generate or manually create the first Pinterest pin batch from `05_Content/pinterest/pin_prompt_bank.md`.
5. Run a local PHP preview and test the opt-in path before publishing.
