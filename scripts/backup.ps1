param(
    # Retention of 0 (default) keeps every backup and log archive forever. Set >0 only if you explicitly want pruning.
    [int]$RetentionDays = 0,
    # If >0, attempt to sleep the machine after backup when user idle at least this many minutes.
    [int]$SleepIfIdleMinutes = 0,
    # Optional: copy the backups folder to this destination (e.g., \\server\share\pinksheet-backups).
    [string]$CopyTo = '',
    # Optional: mirror photos to this destination (e.g., \\server\share\pinksheet-photos). Skipped if blank.
    [string]$CopyPhotosTo = ''
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
    try {
        $hash = Get-FileHash -Path $dest -Algorithm SHA256
        $hash | Select-Object -ExpandProperty Hash | Out-File -FilePath ($dest + '.sha256') -Encoding ascii -NoNewline
        Write-Host "SHA256 written: $($dest + '.sha256')"
    } catch {
        Write-Warning "Could not write checksum: $_"
    }
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

# Prune old backups and archived logs only when explicitly requested.
if ($RetentionDays -gt 0) {
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
} else {
    Write-Host "Retention set to 0; keeping all backups and archived logs."
}

# Optional off-box copy for redundancy.
# Default to OneDrive if not provided.
if (-not $CopyTo) {
    $oneDrive = Join-Path $env:UserProfile 'OneDrive'
    if (Test-Path $oneDrive) {
        $CopyTo = Join-Path $oneDrive 'pinksheet-backups'
    }
}

if ($CopyTo) {
    try {
        if (-not (Test-Path $CopyTo)) {
            New-Item -ItemType Directory -Force -Path $CopyTo | Out-Null
        }
        Write-Host "Copying backups to $CopyTo ..."
        $robocopy = Start-Process -FilePath "robocopy.exe" -ArgumentList @("$backupDir", "$CopyTo", "*.*", "/MIR") -NoNewWindow -PassThru -Wait
        if ($robocopy.ExitCode -gt 8) {
            Write-Warning "Robocopy reported errors (exit $($robocopy.ExitCode))."
        } else {
            Write-Host "Off-box copy completed (exit $($robocopy.ExitCode))."
        }
    } catch {
        Write-Warning "Off-box copy failed: $_"
    }
}

# Optional photo mirror (can be large; use only if storage allows).
# Intentionally does NOT default on. Pass -CopyPhotosTo explicitly to enable.
if ($CopyPhotosTo) {
    try {
        if (-not (Test-Path $CopyPhotosTo)) {
            New-Item -ItemType Directory -Force -Path $CopyPhotosTo | Out-Null
        }
        Write-Host "Mirroring photos to $CopyPhotosTo ..."
        $photoDir = Join-Path $repoRoot 'data\sku_photos'
        $robocopyPhotos = Start-Process -FilePath "robocopy.exe" -ArgumentList @("$photoDir", "$CopyPhotosTo", "*.*", "/MIR") -NoNewWindow -PassThru -Wait
        if ($robocopyPhotos.ExitCode -gt 8) {
            Write-Warning "Photo mirror reported errors (exit $($robocopyPhotos.ExitCode))."
        } else {
            Write-Host "Photo mirror completed (exit $($robocopyPhotos.ExitCode))."
        }
    } catch {
        Write-Warning "Photo mirror failed: $_"
    }
}

# Put machine to sleep if idle long enough and requested.
if ($SleepIfIdleMinutes -gt 0) {
    $kernel = @"
using System;
using System.Runtime.InteropServices;
public static class IdleTime {
    [StructLayout(LayoutKind.Sequential)]
    struct LASTINPUTINFO { public int cbSize; public uint dwTime; }
    [DllImport("user32.dll")]
    static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);
    public static TimeSpan GetIdleTime() {
        LASTINPUTINFO info = new LASTINPUTINFO();
        info.cbSize = Marshal.SizeOf(info);
        if (!GetLastInputInfo(ref info)) return TimeSpan.Zero;
        uint idleMillis = unchecked((uint)Environment.TickCount) - info.dwTime;
        return TimeSpan.FromMilliseconds(idleMillis);
    }
}
"@
    Add-Type $kernel -ErrorAction SilentlyContinue | Out-Null
    $idle = [IdleTime]::GetIdleTime()
    if ($idle.TotalMinutes -ge $SleepIfIdleMinutes) {
        Write-Host "Idle for $([int]$idle.TotalMinutes) min; attempting to sleep..."
        try {
            Add-Type -AssemblyName System.Windows.Forms | Out-Null
            [System.Windows.Forms.Application]::SetSuspendState('Suspend', $false, $false) | Out-Null
            Write-Host "Sleep requested."
        } catch {
            Write-Warning "Sleep request failed: $_"
        }
    } else {
        Write-Host "User active (idle $([int]$idle.TotalMinutes) min); skipping sleep."
    }
}
