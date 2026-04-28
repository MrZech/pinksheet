# Backup and Restore Playbook

Use this when the live database is corrupt, a restore is needed, or the archive and photo stores need to be rolled back with the data.

## Before You Start

1. Pause writes to the app if you can.
2. Note the current timestamps of `data/intake.sqlite` and `data/archive.sqlite`.
3. Find the newest backup that predates the incident.
4. Decide whether this is a full restore, an archive refresh, or a photo-only recovery.

## Restore `data/intake.sqlite`

### Manual Restore

1. Stop the site or block writes.
2. Make a safety copy of the current database.
3. Copy the chosen backup over `data/intake.sqlite`.
4. Restart the app.
5. Rebuild the archive database if needed.

### Helper Restore

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore_latest_backup.ps1
```

Add `-DryRun` to preview the action without copying anything.

The helper script makes a safety copy of the current database before restoring the newest backup.

## Restore `data/archive.sqlite`

Use this when the archive page is stale or the standalone archive database is missing.

1. If the live archive table is still present in `data/intake.sqlite`, rebuild the archive database.
2. If you have a known-good standalone copy, replace `data/archive.sqlite` directly.
3. Reopen `archive.php` and confirm the row count looks right.

```bash
php scripts/build_archive_db.php
```

## Restore Photos

Photos are stored on disk, not inside the SQLite database.

1. Find the matching SKU folder in your backup mirror.
2. Copy that folder back into `data/sku_photos/`.
3. Confirm the photo metadata rows still exist in `sku_photos`.
4. Open `photo.php?id=...` and confirm the file streams correctly.

If only one SKU is affected, restoring that SKU folder is usually enough.

## Full Photo Rollback

Use this only when many photo folders need to be replaced.

1. Back up the current `data/sku_photos/` tree.
2. Mirror the backup copy over the live photo tree.
3. Spot-check a few known images.

## Verify After Restore

1. Run the database check.
2. Verify the newest backup.
3. Open Home and make sure the alert banner is clear.
4. Search a restored SKU in lookup.
5. Open the archive page and confirm the archive row count looks right.
6. Run the smoke test if you changed more than one layer.

```bash
php scripts/check_db.php data/intake.sqlite
php scripts/smoke.php
```

## Common Recovery Paths

- One bad row: use `Copy fields from SKU` or `undo_delete.php`.
- A few bad rows: restore from the soft-delete table or an autosave draft.
- Corrupt live database: restore `data/intake.sqlite` from backup.
- Stale archive: rebuild `data/archive.sqlite`.
- Missing photos: restore the affected SKU folders only.

## After Action

- Write down what was restored.
- Record which backup was used.
- Note whether archive or photo data needed extra rebuilds.
- Fix the cause of the failure before the next shift if possible.
