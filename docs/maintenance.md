# Maintenance

## Backups & logs
- Script: `scripts/backup.ps1` copies `data/intake.sqlite` to `data/backups/` and rotates `logs/lookup.csv` into `logs/archive/`. Retention defaults to **0** (no pruning); pass `-RetentionDays N` only if you really want old backups trimmed.
- Integrity check + alerts: `scripts/verify_backup.ps1` runs `PRAGMA integrity_check` against the live DB and the newest backup. To email on failure, copy `scripts/alert.config.sample.ps1` to `scripts/alert.config.ps1`, fill SMTP settings, and rerun/schedule. The scheduled task will send an email only on failure.
- Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 5` (run elevated). It now chains backup then integrity check. Task name defaults to `PinksheetNightlyBackup`.
- Sleep option: `-SleepIfIdleMinutes` will put the machine to sleep after the backup if idle at least that many minutes; set to `0` to disable.
- Restore: stop the app, pick a backup from `data/backups/`, copy over `data/intake.sqlite`, then start the app.
- Git hooks: `.githooks/pre-commit` blocks staging DB/backups/logs and runs a backup; `.githooks/pre-push` runs a backup before every push. `core.hooksPath` is already set here; on a fresh clone run `git config core.hooksPath .githooks` to enable them.

## Health
- `health.php` reports maintenance flag and limit values for probes.
- `config.php` has `MAINTENANCE_MODE`; flip to true for downtime banner + 503 behavior.

## Database care
- Optional: run `VACUUM; ANALYZE;` occasionally via `sqlite3 data/intake.sqlite` if the DB grows/shrinks a lot.
- Off-box copy: after backups, consider copying `data/backups/` to a NAS/OneDrive/SharePoint location (e.g., `robocopy data\\backups \\path\\to\\share /MIR`).
- WAL mode: `scripts/migrate.php` sets `PRAGMA journal_mode=WAL` and `synchronous=NORMAL`; rerun it if you move the DB to re-apply settings and indexes (`status, updated_at`).

## Space management
- Backups/log archives older than retention are pruned in the backup script. Tight on space? Lower `RetentionDays` when scheduling.

## Task visibility
- Enable Task Scheduler history (Task Scheduler → View → Enable All Tasks History) to track runs/failures.
- Inspect task: `Get-ScheduledTask -TaskName PinksheetNightlyBackup | Format-List TaskName,State,LastRunTime,NextRunTime`.
