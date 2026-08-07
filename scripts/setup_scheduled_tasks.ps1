# Setup Windows Scheduled Tasks for Pinksheet
# Registers: sync queue worker, inventory reconciliation, nightly backup+verify (with email alerts).
# Run as Administrator:  PowerShell -ExecutionPolicy Bypass .\scripts\setup_scheduled_tasks.ps1

$ProjectRoot = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
$PhpPath = "php"   # Change to full path if needed, e.g. "C:\php\php.exe"

# ── Sync Queue Worker (runs every 2 minutes) ────────────────────
$queueAction = New-ScheduledTaskAction -Execute $PhpPath -Argument "$ProjectRoot\scripts\process_sync_queue.php --limit=20"
$queueTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration ([TimeSpan]::MaxValue)
Register-ScheduledTask -TaskName "Pinksheet-SyncQueue" -Action $queueAction -Trigger $queueTrigger -RunLevel Limited -Force
Write-Host "[OK] Pinksheet-SyncQueue — runs every 2 minutes"

# ── Inventory Reconciliation (runs daily at 3:00 AM) ────────────
$reconAction = New-ScheduledTaskAction -Execute $PhpPath -Argument "$ProjectRoot\scripts\reconcile_square.php"
$reconTrigger = New-ScheduledTaskTrigger -Daily -At "03:00"
Register-ScheduledTask -TaskName "Pinksheet-Reconciliation" -Action $reconAction -Trigger $reconTrigger -RunLevel Limited -Force
Write-Host "[OK] Pinksheet-Reconciliation — runs daily at 3:00 AM"

# ── Nightly Backup + Verify (runs daily at 12:15 AM) ────────────
$backupTask = Join-Path $PSScriptRoot 'register_backup_task.ps1'
if (Test-Path $backupTask) {
    & $backupTask -TaskName 'PinksheetNightlyBackup' -Hour 0 -Minute 15 -RetentionDays 0
    Write-Host "[OK] PinksheetNightlyBackup — nightly at 12:15 AM (backup + integrity verify + email alerts)"
} else {
    Write-Warning "register_backup_task.ps1 not found; nightly backup NOT registered."
}

# ── Health check on schedule (optional: set your SMTP in alert.config.ps1) ──
$healthAction = New-ScheduledTaskAction -Execute $PhpPath -Argument "$ProjectRoot\scripts\check_db.php $ProjectRoot\data\intake.sqlite"
$healthTrigger = New-ScheduledTaskTrigger -Daily -At "08:00"
Register-ScheduledTask -TaskName "Pinksheet-DbHealth" -Action $healthAction -Trigger $healthTrigger -RunLevel Limited -Force
Write-Host "[OK] Pinksheet-DbHealth — daily integrity check at 8:00 AM"

Write-Host ""
Write-Host "Done. Verify with: Get-ScheduledTask -TaskName Pinksheet-* | Format-Table TaskName,State"
Write-Host "Optional: configure email alerts in scripts/alert.config.ps1 (copy from alert.config.sample.ps1), then test with scripts/send_test_email.ps1"
