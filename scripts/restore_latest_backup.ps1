param(
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$backupDir = Join-Path $repoRoot 'data\backups'
$dbPath = Join-Path $repoRoot 'data\intake.sqlite'

if (-not (Test-Path $backupDir)) {
    throw "Backup directory not found at $backupDir"
}

$latest = Get-ChildItem -Path $backupDir -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $latest) {
    throw "No backup files found in $backupDir"
}

Write-Host "Latest backup: $($latest.Name) ($([int]($latest.Length/1KB)) KB) modified $($latest.LastWriteTime)"

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$safetyCopy = "$dbPath.before-restore-$timestamp.bak"

if ($DryRun) {
    Write-Host "[DryRun] Would copy $dbPath to $safetyCopy"
    Write-Host "[DryRun] Would restore $($latest.FullName) to $dbPath"
    exit 0
}

if (Test-Path $dbPath) {
    Copy-Item -Path $dbPath -Destination $safetyCopy -Force
    Write-Host "Backed up current DB to $safetyCopy"
}

Copy-Item -Path $latest.FullName -Destination $dbPath -Force
Write-Host "Restored $($latest.Name) to $dbPath"

Write-Host "Done. Consider running: php scripts/check_db.php"
