# Maintenance

## Backups & logs
- Script: `scripts/backup.ps1` copies `data/intake.sqlite` to `data/backups/`, writes `intake-*.sha256` checksums, and rotates `logs/lookup.csv` into `logs/archive/`. Retention defaults to **0** (no pruning); pass `-RetentionDays N` only if you really want old backups trimmed.
- Integrity check + alerts: `scripts/verify_backup.ps1` verifies checksum (if present) and runs `PRAGMA integrity_check` against the live DB and the newest backup. To email on failure or success, copy `scripts/alert.config.sample.ps1` to `scripts/alert.config.ps1`, fill SMTP settings. The scheduled task passes `-NotifyAlways` so you get a nightly success email plus failures.
- Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 5` (run elevated). It now chains backup then integrity check. Task name defaults to `PinksheetNightlyBackup`.
- Sleep option: `-SleepIfIdleMinutes` will put the machine to sleep after the backup if idle at least that many minutes; set to `0` to disable.
- OneDrive mirror (default): `scripts/backup.ps1` now copies every backup + checksum to `%UserProfile%\\OneDrive\\pinksheet-backups` when OneDrive is present. Override with `-CopyTo <path>` if you prefer a different mirror (UNC/NAS/S3 via rclone, etc.).
- Photo mirror (optional): pass `-CopyPhotosTo <path>` to mirror `data/sku_photos/` (robocopy /MIR; can be large). If omitted but `-CopyTo` is set, photos mirror to `<CopyTo>\\sku_photos`.
- Restore: stop the app, pick a backup from `data/backups/`, copy over `data/intake.sqlite`, then start the app.
- Quick restore helper: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore_latest_backup.ps1` (makes a safety copy of the current DB, then restores the newest backup; `-DryRun` to preview).
- Git hooks: `.githooks/pre-commit` blocks staging DB/backups/logs and runs a backup; `.githooks/pre-push` runs a backup before every push. `core.hooksPath` is already set here; on a fresh clone run `git config core.hooksPath .githooks` to enable them.
- Run-now button: Home page “Run backup now” calls `backup_now.php` (local-only) which invokes `scripts/backup.ps1`.

## Health
- `health.php` reports maintenance flag, last-backup name/age/size, and checksum status for probes.
- `config.php` has `MAINTENANCE_MODE`; flip to true for downtime banner + 503 behavior.

## Database care
- Optional: run `VACUUM; ANALYZE;` occasionally via `sqlite3 data/intake.sqlite` if the DB grows/shrinks a lot.
- Off-box copy: after backups, consider copying `data/backups/` to a NAS/OneDrive/SharePoint location (e.g., `robocopy data\\backups \\path\\to\\share /MIR`).
- WAL mode: `scripts/migrate.php` sets `PRAGMA journal_mode=WAL` and `synchronous=NORMAL`; rerun it if you move the DB to re-apply settings and indexes (`status, updated_at`).
- Off-box option: `scripts/backup.ps1 -CopyTo <path>` mirrors `data/backups` to a share; check exit code in console output.

## Space management
- Backups/log archives older than retention are pruned in the backup script. Tight on space? Lower `RetentionDays` when scheduling.

## Task visibility
- Enable Task Scheduler history (Task Scheduler → View → Enable All Tasks History) to track runs/failures.
- Inspect task: `Get-ScheduledTask -TaskName PinksheetNightlyBackup | Format-List TaskName,State,LastRunTime,NextRunTime`.
