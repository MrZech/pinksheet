param(
    [switch]$Quiet
)

$ErrorActionPreference = 'Stop'

# Resolve paths relative to the repo root.
$repoRoot = Split-Path -Parent $PSScriptRoot
$dbPath = Join-Path $repoRoot 'data\intake.sqlite'
$backupDir = Join-Path $repoRoot 'data\backups'

function Get-PhpPath {
    $embedded = Join-Path $repoRoot 'php-8.5.4\php.exe'
    if (Test-Path $embedded) { return $embedded }
    return 'php'
}

function Check-DbIntegrity([string]$path, [string]$label) {
    if (-not (Test-Path $path)) {
        throw "$label not found at $path"
    }
    $php = Get-PhpPath
    $checker = Join-Path $PSScriptRoot 'check_db.php'
    $command = @('-d', 'detect_unicode=0', '-f', $checker, ($path -replace '\\', '/'), $label)
    & $php @command 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "$label failed integrity check (exit $LASTEXITCODE)"
    }
    if (-not $Quiet) { Write-Host "$label integrity_check: ok" }
}

# Main DB
Check-DbIntegrity -path $dbPath -label 'Primary DB'

# Latest backup, if any
if (Test-Path $backupDir) {
    $latest = Get-ChildItem -Path $backupDir -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($latest) {
        Check-DbIntegrity -path $latest.FullName -label "Latest backup ($($latest.Name))"
    } elseif (-not $Quiet) {
        Write-Warning "No backup files found in $backupDir"
    }
} elseif (-not $Quiet) {
    Write-Warning "Backup directory not found at $backupDir"
}
