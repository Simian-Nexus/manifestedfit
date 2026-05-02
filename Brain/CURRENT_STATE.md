# Current State

Last updated: 2026-05-01

## Project Shape

Manifested Fit is being rebuilt as a Codex-friendly affiliate funnel for `manifestedfit.com`.

The project is now a Git repository on branch `main` with remote `origin` set to `https://github.com/Simian-Nexus/manifestedfit.git`.

The project now has:

- a lightweight static/PHP website scaffold in `03_Website/public/`
- an interactive 7-day mind-body reset lead magnet
- initial strategy, content, launch, and deployment docs
- a target-folder deployment pattern where `07_Deploy/targets/prod/config.json` is the ignored production FTP config
- a local memory system in `Brain/`

Local verification has passed for PHP syntax, PowerShell deploy-script parsing, main page responses, and the starter POST lead capture endpoint.

## Working Assumptions

- Initial hosting is shared hosting reachable by FTP or FTPS.
- The first production version should stay simple and publishable without a Node runtime.
- The email capture endpoint is a starter bridge, not the final email marketing system.
- The old archive is reference material, not the working source.

## External Notes

Mindvalley is the initial aspirational affiliate offer. Its official affiliate page currently points applications to Impact and says the affiliate dashboard is hosted on Impact.
