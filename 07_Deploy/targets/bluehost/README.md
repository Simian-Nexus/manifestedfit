# Bluehost Target

This target publishes the website to the Bluehost FTP/FTPS account for `manifestedfit.com`.

## Real Config

Create or edit:

```text
07_Deploy/targets/bluehost/config.json
```

That file contains the real Bluehost FTP password and is ignored by Git.

Use the same shape as `config.example.json`.

## Publish

From the project root:

```powershell
.\07_Deploy\tools\publish-ftp-files.ps1 -All
```

The publish helper uses this target by default.

