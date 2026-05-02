# Deployment

This folder holds FTP/FTPS publishing tools for `manifestedfit.com`.

## Targets

Deployment config is organized by target folder. The folder name carries the intent, while each target can use the same plain config filename.

```text
07_Deploy/targets/
  prod/
    config.example.json
    config.json
  local-preview/
    README.md
```

`config.json` files are ignored by Git because they can contain secrets.

## Production Config

Copy or create:

```powershell
Copy-Item .\07_Deploy\targets\prod\config.example.json .\07_Deploy\targets\prod\config.json
```

Then edit `07_Deploy/targets/prod/config.json` with the real production FTP password.

For localhost preview, use `07_Deploy/targets/local-preview/README.md`. Localhost does not need FTP credentials.

## Publish

Upload the full public site:

```powershell
.\07_Deploy\tools\publish-ftp-files.ps1 -All
```

Upload specific files:

```powershell
.\07_Deploy\tools\publish-ftp-files.ps1 -Files index.html,assets/css/styles.css
```

The helper uploads with `curl.exe` and downloads each uploaded file to verify the SHA256 hash.

## Notes

- Prefer an FTP account rooted directly at the `manifestedfit.com` web root.
- If the FTP account is rooted above the web root, set `remotePath` in `07_Deploy/targets/prod/config.json`.
- Do not store real credentials in docs, memory, or Git.
