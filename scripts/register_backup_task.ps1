param(
    [string]$TaskName = 'PinksheetNightlyBackup',
    [int]$Hour = 0,
    [int]$Minute = 15,
    # Default to 0 so we never prune backups unless explicitly requested.
    [int]$RetentionDays = 0,
    [int]$SleepIfIdleMinutes = 5
)

$ErrorActionPreference = 'Stop'

# Resolve key paths relative to repo root.
$repoRoot = Split-Path -Parent $PSScriptRoot
$backupScript = Join-Path $PSScriptRoot 'backup.ps1'

if (-not (Test-Path $backupScript)) {
    throw "Cannot find backup script at $backupScript"
}

# Build actions: backup then integrity check.
$verifyScript = Join-Path $PSScriptRoot 'verify_backup.ps1'
$actions = @()
$actions += New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" -RetentionDays $RetentionDays -SleepIfIdleMinutes $SleepIfIdleMinutes"
if (Test-Path $verifyScript) {
    $actions += New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$verifyScript`" -Quiet"
}

# Trigger: daily at the requested time (defaults to 12:15 AM).
$runTime = [DateTime]::Today.AddHours($Hour).AddMinutes($Minute)
$trigger = New-ScheduledTaskTrigger -Daily -At $runTime

# Use current user context; prompt for password if needed when registering.
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -WakeToRun
$task = New-ScheduledTask -Action $actions -Trigger $trigger -Principal $principal -Settings $settings

# Register or replace existing.
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force

Write-Host "Scheduled task '$TaskName' created to run nightly at $([string]::Format('{0:00}:{1:00}', $Hour, $Minute)) with retention $RetentionDays days (sleep if idle >= $SleepIfIdleMinutes min)."
Write-Host "Action: powershell -NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" -RetentionDays $RetentionDays -SleepIfIdleMinutes $SleepIfIdleMinutes"
Write-Host "You can edit the time with: Unregister-ScheduledTask -TaskName $TaskName -Confirm:\$false; then rerun this script with new -Hour/-Minute."
