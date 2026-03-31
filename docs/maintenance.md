# Maintenance

## Backups & logs
- Script: `scripts/backup.ps1` copies `data/intake.sqlite` to `data/backups/` and rotates `logs/lookup.csv` into `logs/archive/`. Retention now defaults to **0** (no pruning); pass `-RetentionDays N` only if you really want old backups trimmed.
- Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 5` (run elevated). Task name defaults to `PinksheetNightlyBackup`.
- Sleep option: `-SleepIfIdleMinutes` will put the machine to sleep after the backup if idle at least that many minutes; set to `0` to disable.
- Restore: stop the app, pick a backup from `data/backups/`, copy over `data/intake.sqlite`, then start the app.
- Git commits run the backup automatically via `.githooks/pre-commit` (core.hooksPath is already set in this clone). If you re-clone elsewhere, set `git config core.hooksPath .githooks` to keep autosave-on-commit behavior.

## Health
- `health.php` reports maintenance flag and limit values for probes.
- `config.php` has `MAINTENANCE_MODE`; flip to true for downtime banner + 503 behavior.

## Database care
- Optional: run `VACUUM; ANALYZE;` occasionally via `sqlite3 data/intake.sqlite` if the DB grows/shrinks a lot.

## Space management
- Backups/log archives older than retention are pruned in the backup script. Tight on space? Lower `RetentionDays` when scheduling.

## Task visibility
- Enable Task Scheduler history (Task Scheduler → View → Enable All Tasks History) to track runs/failures.
- Inspect task: `Get-ScheduledTask -TaskName PinksheetNightlyBackup | Format-List TaskName,State,LastRunTime,NextRunTime`.
