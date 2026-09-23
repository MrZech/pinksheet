param(
    [switch]$Quiet,
    # Send a success email even when integrity checks pass.
    [switch]$NotifyAlways
)

$ErrorActionPreference = 'Stop'
$lastFailure = $null

# Resolve paths relative to the repo root.
$repoRoot = Split-Path -Parent $PSScriptRoot
$dbPath = Join-Path $repoRoot 'data\intake.sqlite'
$backupDir = Join-Path $repoRoot 'data\backups'
$alertConfigPath = Join-Path $PSScriptRoot 'alert.config.ps1'

function Get-PhpPath {
    $embedded = Join-Path $repoRoot 'php-8.5.4\php.exe'
    if (Test-Path $embedded) { return $embedded }
    return 'php'
}

function Get-SmtpCredential($config) {
    if ($config.PasswordSecureStringPath -and (Test-Path $config.PasswordSecureStringPath)) {
        $secure = Get-Content $config.PasswordSecureStringPath | ConvertTo-SecureString
        return New-Object System.Management.Automation.PSCredential($config.Username, $secure)
    }
    if ($config.Password) {
        $secure = ConvertTo-SecureString $config.Password -AsPlainText -Force
        return New-Object System.Management.Automation.PSCredential($config.Username, $secure)
    }
    return $null
}

function Send-Alert($subject, $body) {
    if (-not (Test-Path $alertConfigPath)) {
        if (-not $Quiet) { Write-Warning "Alert config missing at $alertConfigPath; skipping email." }
        return
    }
    . $alertConfigPath
    if (-not $AlertConfig) { Write-Warning "Alert config file missing `$AlertConfig; skipping email."; return }
    try {
        $cred = Get-SmtpCredential -config $AlertConfig
        $params = @{
            SmtpServer = $AlertConfig.Server
            Port       = $AlertConfig.Port
            UseSsl     = $AlertConfig.UseSsl
            From       = $AlertConfig.From
            To         = $AlertConfig.To -join ','
            Subject    = $subject
            Body       = $body
        }
        if ($cred) { $params.Credential = $cred }
        Send-MailMessage @params
        if (-not $Quiet) { Write-Host "Alert email sent." }
    } catch {
        Write-Warning "Failed to send alert email: $_"
    }
}

function Check-DbIntegrity([string]$path, [string]$label) {
    if (-not (Test-Path $path)) {
        throw "$label not found at $path"
    }
    # Verify checksum if present
    $hashFile = "$path.sha256"
    if (Test-Path $hashFile) {
        $expected = (Get-Content $hashFile).Trim()
        $actual = (Get-FileHash -Path $path -Algorithm SHA256).Hash
        if ($actual -ne $expected) {
            throw "$label checksum mismatch (expected $expected, got $actual)"
        } elseif (-not $Quiet) {
            Write-Host "$label checksum ok"
        }
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

try {
    # Main DB
    Check-DbIntegrity -path $dbPath -label 'Primary DB'

    # Latest backup, if any
    if (Test-Path $backupDir) {
        # Only *.sqlite files are databases — each snapshot also writes a
        # same-named *.sqlite.sha256 sidecar a moment later, so without this
        # filter "most recently written file" was always the checksum text
        # file, not the database, and Check-DbIntegrity below ran a SQLite
        # integrity check against a 64-byte hex string on every single run.
        # Group by prefix (intake-/archive-) so both databases actually get
        # verified instead of only ever the one written last in a run.
        $backupFiles = Get-ChildItem -Path $backupDir -File -Filter '*.sqlite'
        $groups = $backupFiles | Group-Object { ($_.BaseName -split '-')[0] }
        if ($groups) {
            $lastBackupNames = @()
            foreach ($group in $groups) {
                $latest = $group.Group | Sort-Object LastWriteTime -Descending | Select-Object -First 1
                Check-DbIntegrity -path $latest.FullName -label "Latest $($group.Name) backup ($($latest.Name))"
                $lastBackupNames += $latest.Name
            }
            $lastBackupName = $lastBackupNames -join ', '
        } elseif (-not $Quiet) {
            Write-Warning "No backup files found in $backupDir"
        }
    } elseif (-not $Quiet) {
        Write-Warning "Backup directory not found at $backupDir"
    }
    if ($NotifyAlways) {
        $subject = "[Pinksheet] Backup OK"
        $backupLabel = 'n/a'
        if ($lastBackupName) { $backupLabel = $lastBackupName }
        $body = "Backup verification succeeded.`nLatest backup: $backupLabel"
        Send-Alert -subject $subject -body $body
    }
} catch {
    $lastFailure = $_.Exception.Message
    Send-Alert -subject "[Pinksheet] Backup integrity failed" -body $lastFailure
    throw
}
