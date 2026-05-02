# Local Preview Target

This target is for previewing the website on this computer.

There are no FTP credentials for localhost. The local preview reads directly from:

```text
03_Website/public
```

Start a PHP preview server from the project root:

```powershell
php -S 127.0.0.1:8098 -t 03_Website/public
```

Then open:

```text
http://127.0.0.1:8098/
```

If a future local-only runtime config is needed, put it in this folder as `config.json`. That name is ignored by Git under every target folder.

