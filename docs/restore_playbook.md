# Backup & Restore Playbook (Dispo.Tech Intake)

> [!TIP]
> **Related:** [Maintenance](maintenance.md) (backups & scheduling) · [Ops](ops.md) · [Testing](testing.md)

Use this when intake data is missing/corrupt, or when rolling back after a bad deploy. Steps assume local access to the server filesystem. Times are in local server time.

## What is covered
- Restore `data/intake.sqlite` from backup.
- Restore or rebuild `data/archive.sqlite`.
- Restore SKU photos for one or many SKUs.
- Verify integrity after restore.
- Roll back from a bad deploy using the last known-good backup.

## Before you start (checklist)
1. Confirm no one is actively writing to the app (coordinate a short freeze).
2. Note current `data/intake.sqlite` timestamp/size (for potential forensic diff).
3. Note current `data/archive.sqlite` timestamp/size if the archive page is stale.
4. Identify the target backup file in `data/backups/` (choose the newest that predates the incident).
5. If only a few SKUs lost photos, prefer a targeted photo restore (see below).

## Restore the database
> Goal: replace `data/intake.sqlite` with a known-good backup safely.

1. Stop the PHP server / block writes (if using `php -S`, just stop it; otherwise disable the site briefly).
2. Backup the broken file (just in case):

   ```text
   copy data\intake.sqlite data\intake.sqlite.broken.%DATE:~-4%%DATE:~4,2%%DATE:~7,2%.bak
   ```

3. Pick a backup file from `data\backups\` (example: `intake-2026-04-02-0100.sqlite`).
4. Restore:

   ```text
   copy /Y data\backups\intake-YYYY-MM-DD-HHMM.sqlite data\intake.sqlite
   ```

5. Rebuild the archive database if needed:

   ```text
   php scripts/build_archive_db.php
   ```

6. Restart the server.
7. If your primary copy is in OneDrive, you can restore directly from there:

   ```text
   copy /Y "%UserProfile%\OneDrive\pinksheet-backups\intake-YYYYMMDD-HHMMSS.sqlite" data\intake.sqlite
   ```

8. Shortcut (latest backup, with safety copy):

   ```text
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore_latest_backup.ps1
   ```

   Add `-DryRun` to see what it would do without copying.

## Restore the archive database
> Use this if `archive.php` is stale or `data/archive.sqlite` is missing.

1. If `data/intake.sqlite` already has the archive rows, rebuild the archive DB:

   ```text
   php scripts/build_archive_db.php
   ```

2. If you need to restore the archive database from a copy, replace `data\archive.sqlite` with the known-good file.
3. Open `archive.php` and confirm the badge shows the expected archive DB path and row count.

## Restore photos (per-SKU)
> Only if certain SKU folders are missing/corrupt.

1. Locate the SKU folder in the backup mirror (or prior copy) under `data\sku_photos\<SKU_NORMALIZED>\`.
2. Copy that folder into `data\sku_photos\` on the live server. Example:

   ```text
   robocopy "backup_mirror\data\sku_photos\ABCD123" "data\sku_photos\ABCD123" /E
   ```

3. No DB change is needed if `sku_photos` entries exist. If records are missing:
   - Option A: re-upload via the app (fastest).
   - Option B: insert rows manually (advanced): match `stored_name`, `mime_type`, `file_size`, `sku_normalized`.

## Full photo rollback (rare)
1. Backup current `data\sku_photos`:

   ```text
   robocopy data\sku_photos data\sku_photos.broken /MIR
   ```

2. Restore from mirror:

   ```text
   robocopy backup_mirror\data\sku_photos data\sku_photos /MIR
   ```

## Verification steps
1. Run integrity check on the restored DB and verify checksum (optional):

   ```text
   php scripts/check_db.php
   ```

   Expect `integrity_check: ok`.
   - If using the UI button flow, you can also hit `backup_now.php` after restore to ensure backups still run (should return `{"ok":true,...}`).
   - To verify checksum of a backup: `certutil -hashfile data\backups\intake-YYYYMMDD-HHMMSS.sqlite SHA256` and compare to the adjacent `.sha256` file.
2. Spot-check in the UI:
   - Open Home (counts load, no alerts).
   - Lookup a restored SKU; verify status + thumbnail loads.
   - Open `photo.php?id=...` for a restored SKU photo.
   - Open `archive.php` and confirm the archive badge shows the expected DB path and row count.
3. Optional smoke suite:

   ```text
   php -S 127.0.0.1:8765 -t .
   php scripts/smoke.php
   ```

## Rollback decision tree
- **Only a few records wrong?** Use "Copy fields from SKU" to reapply fields or restore from draft/autosave; avoid full DB rollback.
- **DB corrupt or many records missing?** Restore `intake.sqlite` from the newest good backup, then rebuild `archive.sqlite`.
- **Archive page stale but intake fine?** Run `php scripts/build_archive_db.php`.
- **Photos missing but DB fine?** Restore only affected SKU folders from photo backup.

## After-action
1. Re-enable the site; notify users the window is over.
2. Note the incident time, chosen backup, and verification outcome in your ops log.
3. If backups were stale or failed, prioritize fixing backup schedule/alerts before next shift.
