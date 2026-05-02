# Session Log

## 2026-05-01

- Created the initial Manifested Fit project scaffold.
- Added local project instructions, memory files, strategy docs, content draft folders, website files, and FTP publish tooling.
- Verified the project was not yet a Git repository.
- Reviewed newer sibling-project deployment patterns, especially the FTP publish-helper style from `Spinning_Monkey_Web`.
- Confirmed the official Mindvalley affiliate application path points to Impact and documents a baseline 30% commission with a 30-day cookie.
- Verified PHP syntax, PowerShell deploy-script parsing, local page responses, and the starter lead capture endpoint.
- Created ignored production FTP config with the server, username, and port; password remains a local placeholder.
- Initialized Git, connected the GitHub remote, excluded local credential files and `Archive/`, committed the initial scaffold, and pushed `main`.
- Renamed the deployment config layout so target folders carry intent: production credentials now live in ignored `07_Deploy/targets/prod/config.json`, and localhost preview guidance lives in `07_Deploy/targets/local-preview/`.
- Renamed the production deployment target from a host-specific name to `prod` so the project is not tied to a hosting provider in folder names or committed docs.
- First production publish attempt reached the upload step but failed before transfer because explicit FTPS reported a certificate principal-name mismatch for the configured FTP host.
