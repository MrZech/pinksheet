# Developer Notes

This page explains how the app is wired together so you can change it without guessing where each feature lives.

## Reading Order

- [Usage](usage.md)
- [Schema](schema.md)
- [Testing](testing.md)
- [Maintenance](maintenance.md)
- [Operator SOP](ops.md)
- [Archive workflow](archive.md)
- [Restore playbook](restore_playbook.md)

## File Map

| File | Role |
|---|---|
| `index.php` | Main intake page, save/update flow, autosave UI, delete and duplicate actions |
| `intake.php` | Thin wrapper that loads `index.php` |
| `home.php` | Dashboard, activity feed, backup controls, and live lookup shell |
| `lookup.php` | Thin wrapper that loads `home.php` |
| `archive.php` | Read-only legacy archive browser |
| `kanban.php` | Status board with drag-and-drop updates |
| `prompt_builder.php` | ChatGPT prompt and eBay script builder |
| `autosave.php` | Server-backed draft storage |
| `script_cache.php` | Prompt builder cache storage |
| `copy_item.php` | Returns the newest row for a SKU without ids or timestamps |
| `lookup_preview.php` | JSON preview data for the dashboard and lookup page |
| `suggestions.php` | Lookup autocomplete JSON |
| `update_item.php` | Local/private update endpoint for status and price changes |
| `delete_item.php` | Deletes a row after double confirmation and writes it to the soft-delete table |
| `undo_delete.php` | Restores the most recent soft-deleted row |
| `upload_photo.php` | Single-file photo upload endpoint |
| `upload_photo_chunk.php` | Chunked photo upload endpoint |
| `set_thumbnail.php` | Marks one photo as the SKU thumbnail |
| `photo.php` | Streams a stored photo back to the browser |
| `download_photos.php` | ZIP download for all photos on one SKU |
| `health.php` | JSON health endpoint for backup age and limits |
| `backup_now.php` | Local/private backup trigger used by the Home button |
| `verify_now.php` | Local/private backup verification trigger used by the Home button |
| `scripts/migrate.php` | Creates tables, directories, and indexes |
| `scripts/smoke.php` | Local smoke test suite |
| `scripts/import_archive_csv.php` | Imports legacy CSV exports |
| `scripts/build_archive_db.php` | Rebuilds `data/archive.sqlite` from live archive rows |
| `scripts/backup.ps1` | Main backup script |
| `scripts/verify_backup.ps1` | Integrity check and optional alert script |
| `scripts/register_backup_task.ps1` | Scheduled task helper for nightly jobs |

## Core Request Flow

### Intake Save

1. The browser posts the form to `index.php`.
2. The server normalizes the SKU.
3. The server updates the newest row for that normalized SKU, or inserts a new row if needed.
4. The page refreshes the table and the record appears in lookup and Kanban views.

### Autosave

1. The browser watches the intake form as you type.
2. It sends JSON to `autosave.php` with the SKU, payload, and current version.
3. The server stores the draft in `intake_drafts`.
4. If another browser has already saved a newer version, the server returns a conflict instead of overwriting.

### Lookup Preview

1. The dashboard sends the search term and status filter to `lookup_preview.php`.
2. The endpoint reads from `intake_items` and optionally joins photo metadata.
3. The page renders status chips, timestamps, thumbnails, and price hints.

### Prompt Builder

1. `prompt_builder.php` loads the latest item with `copy_item.php`.
2. It loads cached prompt text with `script_cache.php`.
3. It builds a ChatGPT prompt from the current record.
4. When the user pastes generated text, the cache is updated again.

### Photo Uploads

1. `upload_photo.php` validates a single file upload.
2. `upload_photo_chunk.php` stages chunk files in `data/chunks/` until the full file is assembled.
3. The file is written under `data/sku_photos/<normalized-sku>/`.
4. Metadata is stored in `sku_photos`.

### Delete And Restore

1. `delete_item.php` copies the live row into `intake_deleted`.
2. The live row is removed from `intake_items`.
3. `undo_delete.php` can restore the most recent deleted row.

## Front-End Behavior

- Theme state is stored in `localStorage` under `themePreference`.
- Print behavior is controlled by `assets/print.css` and a print iframe on the intake page.
- Autosave, duplicate-save, and prompt-cache writes all use browser-side timers so the UI stays responsive.
- Lookup and dashboard filters update the query string and then re-fetch preview data.

## Important Endpoints

### `update_item.php`

- Accepts `status` and `price` updates only.
- Treats price updates as a single canonical value by writing both `dispotech_price` and `ebay_price`.
- Updates `updated_at` on every write.
- Intended for local/private use.

### `delete_item.php`

- Requires both `id` and `sku`.
- Requires the confirmation word `DELETE`.
- Supports both form posts and AJAX requests.
- Uses `intake_deleted` as the soft-delete archive.

### `backup_now.php` and `verify_now.php`

- Reject remote public access.
- Are meant to be triggered from the local LAN or localhost only.
- Call the backup and verification stack used by the scheduled task scripts.

## Local Run

```bash
php -S 127.0.0.1:8765 -t .
php scripts/smoke.php
```

The embedded PHP binary in `php-8.5.4/` is available if the system PHP version is not what you want to use.

## Coding Conventions

- Keep PHP entry points small and direct.
- Treat SQLite as the source of truth for live records.
- Store files on disk when the data is binary or large.
- Normalize SKUs before using them as a lookup key.
- Keep write endpoints explicit about what they change.

## Change Hotspots

- Item fields and workflow labels usually touch `index.php`, `lookup_preview.php`, `home.php`, and `schema.md`.
- Photo behavior usually touches `upload_photo.php`, `upload_photo_chunk.php`, `photo.php`, and `set_thumbnail.php`.
- Backup changes usually touch `scripts/backup.ps1`, `backup_now.php`, `verify_now.php`, and the maintenance docs.
- Archive import changes usually touch `scripts/import_archive_csv.php`, `scripts/build_archive_db.php`, and `archive.php`.
