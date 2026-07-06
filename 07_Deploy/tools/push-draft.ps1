<#
  push-draft.ps1
  Create a WordPress DRAFT post from a Markdown file via the REST API.

  - Reads credentials from 07_Deploy/targets/wordpress/config.json (git-ignored).
  - Parses optional front matter (title, slug, author_id, excerpt, categories).
  - Converts a subset of Markdown to HTML.
  - Creates categories by name if they don't exist yet.
  - ALWAYS creates as a DRAFT. Nothing is ever published by this script.

  Usage (PowerShell, from the tools folder):
    .\push-draft.ps1 -File ..\..\05_Content\blog\_sample-push-test.md

  Front-matter example (between --- lines at the top of the .md file):
    ---
    title: The 5-Minute Morning Reset
    slug: morning-reset-ritual
    author_id: 3
    excerpt: A short meta description for search + social.
    categories: Manifestation, Rituals & Routines
    ---
#>

param(
  [Parameter(Mandatory = $true)][string]$File,
  [string]$ConfigPath = "$PSScriptRoot\..\targets\wordpress\config.json"
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Web

# --- Load config -------------------------------------------------------------
if (-not (Test-Path $ConfigPath)) {
  throw "Config not found at $ConfigPath. Copy config.example.json to config.json and fill it in."
}
$cfg = Get-Content $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
foreach ($k in 'site_url', 'username', 'app_password') {
  if (-not $cfg.$k) { throw "config.json is missing '$k'." }
}
$base = $cfg.site_url.TrimEnd('/')
$pair = "$($cfg.username):$($cfg.app_password)"
$auth = @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair)) }

# --- Read + split front matter ----------------------------------------------
if (-not (Test-Path $File)) { throw "Post file not found: $File" }
$raw = Get-Content $File -Raw -Encoding UTF8
$meta = @{}
$body = $raw
if ($raw -match '(?s)^\s*---\s*\r?\n(.*?)\r?\n---\s*\r?\n(.*)$') {
  foreach ($line in ($matches[1] -split "\r?\n")) {
    if ($line -match '^\s*([A-Za-z0-9_]+)\s*:\s*(.*)$') { $meta[$matches[1].ToLower()] = $matches[2].Trim().Trim('"') }
  }
  $body = $matches[2]
}

# Title = front matter, else first "# " heading (which we then strip once)
$title = $meta['title']
$firstH1 = [regex]::Match($body, '(?m)^\#\s+(.*)$')
if ($firstH1.Success) {
  if (-not $title) { $title = $firstH1.Groups[1].Value.Trim() }
  $body = ([regex]'(?m)^\#\s+.*$').Replace($body, '', 1)
}
if (-not $title) { $title = [IO.Path]::GetFileNameWithoutExtension($File) }

# --- Minimal Markdown -> HTML ------------------------------------------------
function Convert-Inline([string]$t) {
  $t = [System.Web.HttpUtility]::HtmlEncode($t)
  $t = $t -replace '\*\*(.+?)\*\*', '<strong>$1</strong>'
  $t = $t -replace '\[(.+?)\]\((.+?)\)', '<a href="$2">$1</a>'
  return $t
}
$html = [Text.StringBuilder]::new()
$openList = $null   # 'ul' | 'ol' | $null
function Close-List { if ($script:openList) { [void]$html.Append("</$script:openList>`n"); $script:openList = $null } }

foreach ($line in ($body -split "\r?\n")) {
  $l = $line.Trim()
  if ($l -eq '') { Close-List; continue }
  switch -regex ($l) {
    '^###\s+(.*)'   { Close-List; [void]$html.Append("<h3>$(Convert-Inline $matches[1])</h3>`n"); break }
    '^##\s+(.*)'    { Close-List; [void]$html.Append("<h2>$(Convert-Inline $matches[1])</h2>`n"); break }
    '^#\s+(.*)'     { Close-List; [void]$html.Append("<h2>$(Convert-Inline $matches[1])</h2>`n"); break }
    '^>\s+(.*)'     { Close-List; [void]$html.Append("<blockquote><p>$(Convert-Inline $matches[1])</p></blockquote>`n"); break }
    '^[-*]\s+(.*)'  { if ($openList -ne 'ul') { Close-List; [void]$html.Append("<ul>`n"); $script:openList = 'ul' }; [void]$html.Append("<li>$(Convert-Inline $matches[1])</li>`n"); break }
    '^\d+\.\s+(.*)' { if ($openList -ne 'ol') { Close-List; [void]$html.Append("<ol>`n"); $script:openList = 'ol' }; [void]$html.Append("<li>$(Convert-Inline $matches[1])</li>`n"); break }
    default         { Close-List; [void]$html.Append("<p>$(Convert-Inline $l)</p>`n") }
  }
}
Close-List
$content = $html.ToString()

# --- Resolve / create categories by name ------------------------------------
$catIds = @()
if ($meta['categories']) {
  foreach ($name in ($meta['categories'] -split ',')) {
    $n = $name.Trim(); if ($n -eq '') { continue }
    $found = Invoke-RestMethod -Uri "$base/wp-json/wp/v2/categories?search=$([uri]::EscapeDataString($n))" -Headers $auth
    # WP returns names HTML-entity-encoded (e.g. "Rituals &amp; Routines") - decode before comparing.
    $match = $found | Where-Object { [System.Web.HttpUtility]::HtmlDecode($_.name) -eq $n } | Select-Object -First 1
    if ($match) {
      $catIds += $match.id
    } else {
      $catJson = @{ name = $n } | ConvertTo-Json
      try {
        $new = Invoke-RestMethod -Uri "$base/wp-json/wp/v2/categories" -Method Post -Headers $auth -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($catJson))
        $catIds += $new.id
        Write-Host "Created category: $n (id $($new.id))"
      } catch {
        # If WP says the term already exists, recover its id from the error body.
        $respStream = $_.Exception.Response.GetResponseStream()
        $errBody = (New-Object IO.StreamReader($respStream)).ReadToEnd() | ConvertFrom-Json
        if ($errBody.code -eq 'term_exists' -and $errBody.data.term_id) {
          $catIds += [int]$errBody.data.term_id
          Write-Host "Category already exists: $n (id $($errBody.data.term_id))"
        } else {
          throw
        }
      }
    }
  }
}

# --- Build payload (ALWAYS draft) -------------------------------------------
$payload = @{ title = $title; content = $content; status = 'draft' }
if ($meta['slug'])      { $payload.slug = $meta['slug'] }
if ($meta['excerpt'])   { $payload.excerpt = $meta['excerpt'] }
if ($meta['author_id']) { $payload.author = [int]$meta['author_id'] }
if ($catIds.Count -gt 0) { $payload.categories = $catIds }

$json = $payload | ConvertTo-Json -Depth 6
$resp = Invoke-RestMethod -Uri "$base/wp-json/wp/v2/posts" -Method Post -Headers $auth -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($json))

$editUrl = "$base/wp-admin/post.php?post=$($resp.id)" + '&action=edit'
Write-Host ''
Write-Host ("Draft created (id {0}) - status: {1}" -f $resp.id, $resp.status)
Write-Host ("Edit it here: {0}" -f $editUrl)
