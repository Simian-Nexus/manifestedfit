# Deployment

This folder holds FTP/FTPS publishing tools for `manifestedfit.com`.

## Config

Copy:

```powershell
Copy-Item .\07_Deploy\config\ftp-publish.local.example.json .\07_Deploy\config\ftp-publish.local.json
```

Then edit `ftp-publish.local.json` with the real credentials.

The real local config is ignored by Git.

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
- If the FTP account is rooted above the web root, set `remotePath` in the local config.
- Do not store real credentials in docs, memory, or Git.

