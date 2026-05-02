param(
    [string]$ConfigPath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'targets\prod\config.json'),
    [string[]]$Files,
    [switch]$All
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$deployRoot = Split-Path -Parent $PSScriptRoot
$projectRoot = Split-Path -Parent $deployRoot

function Get-Config {
    param([string]$Path)

    if (-not (Test-Path $Path -PathType Leaf)) {
        throw "Config file not found: $Path. Copy 07_Deploy/targets/prod/config.example.json to config.json first."
    }

    return Get-Content -Path $Path -Raw | ConvertFrom-Json
}

function Get-RemoteInfo {
    param([pscustomobject]$Config)

    $sitePublish = $Config.sitePublish
    if (-not $sitePublish) {
        throw 'sitePublish is missing from config.'
    }

    foreach ($field in @('baseUrl', 'username', 'password', 'localRoot')) {
        if ([string]::IsNullOrWhiteSpace([string]$sitePublish.$field)) {
            throw "sitePublish.$field must be set."
        }
    }

    try {
        $baseUri = [Uri]([string]$sitePublish.baseUrl)
    }
    catch {
        throw "sitePublish.baseUrl is not a valid FTP URL: $($sitePublish.baseUrl)"
    }

    if ($baseUri.Scheme -notin @('ftp', 'ftps')) {
        throw "sitePublish.baseUrl must use ftp:// or ftps://."
    }

    $remotePath = [string]$sitePublish.remotePath
    if ([string]::IsNullOrWhiteSpace($remotePath)) {
        $remotePath = '/'
    }

    return [pscustomobject]@{
        BaseUrl        = 'ftp://' + $baseUri.Authority
        IsExplicitFtps = $baseUri.Scheme -eq 'ftps'
        Credential     = '{0}:{1}' -f $sitePublish.username, $sitePublish.password
        RemotePath     = '/' + $remotePath.Trim('/')
    }
}

function Resolve-LocalRoot {
    param([pscustomobject]$Config)

    $rawLocalRoot = [string]$Config.sitePublish.localRoot
    if ([IO.Path]::IsPathRooted($rawLocalRoot)) {
        $localRoot = $rawLocalRoot
    }
    else {
        $localRoot = Join-Path $projectRoot $rawLocalRoot
    }

    $resolved = Resolve-Path -LiteralPath $localRoot -ErrorAction Stop
    return $resolved.Path
}

function Get-RelativePath {
    param(
        [string]$Root,
        [string]$Path
    )

    $rootFullPath = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $pathFullPath = [IO.Path]::GetFullPath($Path)

    if (-not $pathFullPath.StartsWith($rootFullPath, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Path is not inside local root: $pathFullPath"
    }

    return $pathFullPath.Substring($rootFullPath.Length).Replace('\', '/')
}

function Get-PublishableFiles {
    param([string]$LocalRoot)

    $skipNames = @('leads.csv')
    Get-ChildItem -Path $LocalRoot -Recurse -File -Force |
        Where-Object { $_.Name -notin $skipNames } |
        ForEach-Object { Get-RelativePath -Root $LocalRoot -Path $_.FullName }
}

function Join-RemoteUrl {
    param(
        [pscustomobject]$RemoteInfo,
        [string]$RelativePath
    )

    $remotePath = $RemoteInfo.RemotePath.TrimEnd('/')
    if ($remotePath -eq '') {
        $remotePath = '/'
    }

    return ($RemoteInfo.BaseUrl.TrimEnd('/') + $remotePath + '/' + $RelativePath.TrimStart('/')) -replace '([^:])/+', '$1/'
}

function Send-RemoteFile {
    param(
        [pscustomobject]$RemoteInfo,
        [string]$LocalPath,
        [string]$RemoteUrl
    )

    if ($RemoteInfo.IsExplicitFtps) {
        & curl.exe --silent --show-error --fail --user $RemoteInfo.Credential --ssl-reqd --ftp-create-dirs --upload-file $LocalPath $RemoteUrl
    }
    else {
        & curl.exe --silent --show-error --fail --user $RemoteInfo.Credential --ftp-create-dirs --upload-file $LocalPath $RemoteUrl
    }

    if ($LASTEXITCODE -ne 0) {
        throw "curl failed while uploading $LocalPath to $RemoteUrl (exit code $LASTEXITCODE)"
    }
}

function Get-RemoteFile {
    param(
        [pscustomobject]$RemoteInfo,
        [string]$RemoteUrl,
        [string]$DestinationPath
    )

    if ($RemoteInfo.IsExplicitFtps) {
        & curl.exe --silent --show-error --fail --user $RemoteInfo.Credential --ssl-reqd $RemoteUrl -o $DestinationPath
    }
    else {
        & curl.exe --silent --show-error --fail --user $RemoteInfo.Credential $RemoteUrl -o $DestinationPath
    }

    if ($LASTEXITCODE -ne 0) {
        throw "curl failed while verifying $RemoteUrl (exit code $LASTEXITCODE)"
    }
}

function Test-RemoteFileMatchesLocal {
    param(
        [pscustomobject]$RemoteInfo,
        [string]$LocalPath,
        [string]$RemoteUrl
    )

    $tempFile = Join-Path $env:TEMP ([IO.Path]::GetRandomFileName())
    try {
        Get-RemoteFile -RemoteInfo $RemoteInfo -RemoteUrl $RemoteUrl -DestinationPath $tempFile
        $localHash = (Get-FileHash -LiteralPath $LocalPath -Algorithm SHA256).Hash
        $remoteHash = (Get-FileHash -LiteralPath $tempFile -Algorithm SHA256).Hash
        return $localHash -eq $remoteHash
    }
    finally {
        if (Test-Path $tempFile -PathType Leaf) {
            Remove-Item -LiteralPath $tempFile -Force
        }
    }
}

$config = Get-Config -Path $ConfigPath
$localRoot = Resolve-LocalRoot -Config $config
$remoteInfo = Get-RemoteInfo -Config $config

if (-not $All -and (-not $Files -or $Files.Count -eq 0)) {
    throw 'Use -All or pass -Files with paths relative to 03_Website/public.'
}

if ($All) {
    $pathsToPublish = @(Get-PublishableFiles -LocalRoot $localRoot)
}
else {
    $pathsToPublish = @($Files | ForEach-Object { $_.Replace('\', '/') })
}

if ($pathsToPublish.Count -eq 0) {
    Write-Host 'No files to publish.'
    exit 0
}

foreach ($relativePath in ($pathsToPublish | Sort-Object -Unique)) {
    $localPath = Join-Path $localRoot $relativePath
    if (-not (Test-Path $localPath -PathType Leaf)) {
        throw "Local file not found: $localPath"
    }

    $remoteUrl = Join-RemoteUrl -RemoteInfo $remoteInfo -RelativePath $relativePath
    Write-Host "Uploading $relativePath"
    Send-RemoteFile -RemoteInfo $remoteInfo -LocalPath $localPath -RemoteUrl $remoteUrl

    if (-not (Test-RemoteFileMatchesLocal -RemoteInfo $remoteInfo -LocalPath $localPath -RemoteUrl $remoteUrl)) {
        throw "Remote verification failed for $relativePath."
    }
}

Write-Host 'FTP publish complete.'
