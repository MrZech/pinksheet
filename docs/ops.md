# Operator SOP

This is the day-to-day checklist for keeping the app healthy.

## Morning Check

1. Open `home.php`.
2. Confirm the dashboard cards load and counts are not blank.
3. Check the backup badge.
4. Read the alerts area.
5. Open the lookup panel and confirm search still returns recent items.
6. Open `intake.php?clear_draft=1` and confirm autosave works.

## What Good Looks Like

- The backup badge is recent.
- The alert list is empty.
- Lookup preview updates when you type.
- Thumbnails appear when photos exist.
- Autosave reports a saved state instead of a conflict or error.

## Weekly Check

1. Run the smoke test.
2. Verify the latest backup.
3. Confirm the archive database is current after any import work.
4. Check free disk space for `data/backups/`, `data/sku_photos/`, and `logs/`.
5. Spot-check a known SKU in intake, lookup, archive, and the prompt builder.

## Bulk Delete Safety

- Bulk delete is permanent from the live table.
- The UI requires selected rows and the confirmation word `DELETE`.
- Deleted rows are copied into `intake_deleted` first so `undo_delete.php` can recover the most recent one.
- Deleting a record does not delete photo files.

## Photo Safety

- If a SKU loses its photo files, the database rows can still exist.
- If you restore files manually, check that the stored file names still match the database metadata.
- If you need a single thumbnail, use `set_thumbnail.php` or the related UI control.

## Archive Safety

- The archive page is read-only.
- If imported rows are missing, rebuild `data/archive.sqlite`.
- If a legacy CSV import produced duplicates, check the `legacy_source`, `legacy_table`, and `legacy_id` values.

## Common Commands

```bash
php -S 127.0.0.1:8765 -t .
php scripts/smoke.php
php scripts/check_db.php data/intake.sqlite
php scripts/build_archive_db.php
```

## Incident Triage

- Backup stale: run backup, then verify the newest backup.
- Autosave broken: test `autosave.php` directly and confirm the SKU is present.
- Lookup broken: test `lookup_preview.php` and `suggestions.php`.
- Photos missing: check `photo.php`, `upload_photo.php`, and the `data/sku_photos/` folder.
- Kanban move broken: test `update_item.php`.

## Escalation Triggers

- The database fails integrity checks.
- The backup script fails repeatedly.
- The archive database will not rebuild.
- File permissions prevent writes to `data/` or `logs/`.
- A local-only endpoint is somehow reachable from outside the private network.

## Notes For The Next Shift

- Record the backup status you saw.
- Record any manual restore or repair work.
- Record whether photos, archive rows, or drafts needed recovery.
- If you changed retention or mirror settings, leave a note about why.
