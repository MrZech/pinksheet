param(
    [int]$RetentionDays = 14
)

$ErrorActionPreference = 'Stop'

# Resolve paths relative to the repo root.
$repoRoot = Split-Path -Parent $PSScriptRoot
$dbPath = Join-Path $repoRoot 'data\intake.sqlite'
$backupDir = Join-Path $repoRoot 'data\backups'
$logFile = Join-Path $repoRoot 'logs\lookup.csv'
$logArchiveDir = Join-Path $repoRoot 'logs\archive'

New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
New-Item -ItemType Directory -Force -Path $logArchiveDir | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'

if (Test-Path $dbPath) {
    $backupName = "intake-$timestamp.sqlite"
    $dest = Join-Path $backupDir $backupName
    Copy-Item -Path $dbPath -Destination $dest -Force
    Write-Host "SQLite backup created: $dest"
}
else {
    Write-Warning "SQLite database not found at $dbPath"
}

if (Test-Path $logFile) {
    $archiveName = "lookup-$timestamp.csv"
    $archiveDest = Join-Path $logArchiveDir $archiveName
    $length = (Get-Item $logFile).Length
    if ($length -gt 0) {
        Copy-Item -Path $logFile -Destination $archiveDest -Force
        Clear-Content -Path $logFile
        Write-Host "Rotated lookup log to $archiveDest and truncated current log."
    } else {
        Write-Host "Lookup log exists but is empty; nothing to rotate."
    }
} else {
    Write-Warning "Lookup log not found at $logFile"
}

# Prune old backups and archived logs beyond retention.
$cutoff = (Get-Date).AddDays(-$RetentionDays)

Get-ChildItem -Path $backupDir -File |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object {
        Write-Host "Removing old backup $($_.FullName)"
        Remove-Item $_.FullName -Force
    }

Get-ChildItem -Path $logArchiveDir -File |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object {
        Write-Host "Removing old archived log $($_.FullName)"
        Remove-Item $_.FullName -Force
    }
