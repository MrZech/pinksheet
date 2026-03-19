# Maintenance

## Backups & logs
- Script: `scripts/backup.ps1 -RetentionDays 14` copies `data/intake.sqlite` to `data/backups/` and rotates `logs/lookup.csv` into `logs/archive/`.
- Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 14` (run elevated). Task name defaults to `PinksheetNightlyBackup`.
- Restore: stop the app, pick a backup from `data/backups/`, copy over `data/intake.sqlite`, then start the app.

## Health
- `health.php` reports maintenance flag and limit values for probes.
- `config.php` has `MAINTENANCE_MODE`; flip to true for downtime banner + 503 behavior.

## Database care
- Optional: run `VACUUM; ANALYZE;` occasionally via `sqlite3 data/intake.sqlite` if the DB grows/shrinks a lot.

## Space management
- Backups/log archives older than retention are pruned in the backup script. Tight on space? Lower `RetentionDays` when scheduling.
