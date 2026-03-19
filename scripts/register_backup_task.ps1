param(
    [string]$TaskName = 'PinksheetNightlyBackup',
    [int]$Hour = 0,
    [int]$Minute = 15,
    [int]$RetentionDays = 14
)

$ErrorActionPreference = 'Stop'

# Resolve key paths relative to repo root.
$repoRoot = Split-Path -Parent $PSScriptRoot
$backupScript = Join-Path $PSScriptRoot 'backup.ps1'

if (-not (Test-Path $backupScript)) {
    throw "Cannot find backup script at $backupScript"
}

# Build the action to run the backup with the configured retention.
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" -RetentionDays $RetentionDays"

# Trigger: daily at the requested time (defaults to 12:15 AM).
$trigger = New-ScheduledTaskTrigger -Daily -At ([DateTime]::Today.AddHours($Hour).AddMinutes($Minute).TimeOfDay)

# Use current user context; prompt for password if needed when registering.
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Highest

# Build task definition.
$task = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -RunOnlyIfNetworkAvailable $false -AllowStartIfOnBatteries)

# Register or replace existing.
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force

Write-Host "Scheduled task '$TaskName' created to run nightly at $([string]::Format('{0:00}:{1:00}', $Hour, $Minute)) with retention $RetentionDays days."
Write-Host "Action: powershell -NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" -RetentionDays $RetentionDays"
Write-Host "You can edit the time with: Unregister-ScheduledTask -TaskName $TaskName -Confirm:\$false; then rerun this script with new -Hour/-Minute."
