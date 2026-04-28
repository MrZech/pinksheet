# Maintenance

This page covers backups, verification, scheduling, health checks, and restore-adjacent maintenance tasks.

## Quick Reference

| Task | Tool |
|---|---|
| Run backup from the UI | Home -> `Run backup now` -> `backup_now.php` |
| Verify latest backup from the UI | Home -> `Verify latest backup` -> `verify_now.php` |
| Run the PowerShell backup directly | `scripts/backup.ps1` |
| Verify integrity directly | `scripts/verify_backup.ps1` |
| Register a nightly task | `scripts/register_backup_task.ps1` |
| Rebuild the archive database | `scripts/build_archive_db.php` |

## Backup Flow

The main backup script is `scripts/backup.ps1`.

- Default retention is `0`, which means keep everything.
- The script copies `data/intake.sqlite` into `data/backups/`.
- A SHA256 checksum file is written next to the backup.
- `logs/lookup.csv` is rotated into `logs/archive/`.
- By default, OneDrive is used as an off-box mirror when it exists.
- `-CopyTo` mirrors the backup folder to another destination.
- `-CopyPhotosTo` mirrors `data/sku_photos/` when you want photo backups too.
- `-SleepIfIdleMinutes` can put the machine to sleep after the backup completes if the user has been idle long enough.

### Browser Trigger

- `backup_now.php` is the browser-triggered backup endpoint.
- It only allows local or private-network requests.
- It prefers PowerShell when available.
- If PowerShell is unavailable, it falls back to a PHP backup implementation.

## Verification Flow

The verification stack is split across `verify_now.php`, `scripts/verify_backup.ps1`, and `scripts/check_db.php`.

- `verify_now.php` checks the live database and the latest backup.
- It validates the latest backup checksum when a checksum file is present.
- It runs `PRAGMA integrity_check` through `scripts/check_db.php`.
- `scripts/verify_backup.ps1` does the same work from PowerShell and can send email alerts.

### Alerts

- Copy `scripts/alert.config.sample.ps1` to `scripts/alert.config.ps1` to enable email.
- The verify script can send a success message when `-NotifyAlways` is set.
- Failures are sent automatically when alert config is present and configured.

## Scheduling

`scripts/register_backup_task.ps1` creates a scheduled task that runs backup first and verification second.

- Default task name: `PinksheetNightlyBackup`
- Default time: 00:15
- Default retention: `0`
- Default idle sleep threshold: 5 minutes

The script runs the task in the current user context with highest available privileges.

## Health Endpoint

`health.php` exposes a small JSON summary for dashboard and monitoring use.

- Maintenance mode status
- Latest backup name
- Backup age in hours
- Backup size in bytes
- Backup checksum result
- Lookup and preview limits

The dashboard uses this data to warn when backups are stale or checksums look wrong.

## Restore-Adjacent Work

- After restoring `data/intake.sqlite`, run `scripts/build_archive_db.php` if you want `archive.php` to match the imported archive rows.
- If legacy CSV data changed, rebuild `data/archive.sqlite` from the live archive table.
- If you restored or copied photo folders manually, spot-check `photo.php?id=...` and the lookup thumbnails.

## Disk And Retention

- The backup scripts do not prune old files unless you explicitly set a positive retention value.
- Use retention only when you have a clear storage policy.
- Keep an eye on `data/backups/`, `logs/archive/`, and any off-box mirror that is in use.

## Git Hooks

- `.githooks/pre-commit` protects the repository from accidentally staging live databases and backup artifacts.
- `.githooks/pre-push` runs a backup before pushing.
- On a fresh clone, enable them with `git config core.hooksPath .githooks`.

## Database Repair

- `scripts/migrate.php` can recreate missing directories, tables, and indexes.
- It also sets `journal_mode=WAL` and `synchronous=NORMAL`.
- Run it after moving the database file or after a very old schema is introduced into a new checkout.

## When Something Looks Wrong

- If the backup badge on Home is older than expected, run a backup and verify it immediately.
- If `verify_now.php` fails, check the checksum file, the live database, and the latest backup side by side.
- If `health.php` says maintenance mode is on, the app is intentionally returning maintenance responses.
- If the archive page is stale after an import or restore, rebuild `data/archive.sqlite`.
