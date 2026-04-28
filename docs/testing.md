# Testing

This page covers the automated smoke test and the manual checks worth running after a change.

## Automated Smoke Test

The smoke test expects a local server on `127.0.0.1:8765`.

```bash
php -S 127.0.0.1:8765 -t .
php scripts/smoke.php
```

### What The Smoke Test Hits

- `GET /health.php`
- `GET /home.php`
- `GET /lookup.php`
- `GET /intake.php`
- `GET /prompt_builder.php`
- `GET /lookup_preview.php?sku=TEST&limit=3`
- `POST /autosave.php`
- `POST /script_cache.php`
- `GET /script_cache.php?sku=...`
- `POST /upload_photo.php` when the PHP `curl` extension is available

### How To Read The Output

- `[OK]` means the endpoint responded in the expected range.
- `[FAIL]` means the endpoint returned a bad status code or invalid body.
- `[SKIP]` on photo upload means the PHP installation lacks `curl`, so the rest of the smoke test still matters.

### When To Run It

- After editing intake, lookup, autosave, photo, prompt builder, or backup code.
- After touching `schema.md` or `migrate.php`.
- Before a deployment or a handoff.

## Manual Checks

1. Open Home and confirm the dashboard loads.
2. Open the intake page, type a few values, and confirm autosave status updates.
3. Clear the form and confirm the restore-last-draft behavior appears when expected.
4. Save an item, then use `Copy fields from SKU` on a new form.
5. Save and duplicate a record, then confirm the new form has the SKU cleared.
6. Upload a photo, then check the thumbnail in lookup or Home.
7. Mark a different photo as the thumbnail and confirm the preview changes.
8. Move items through Kanban and confirm status updates stick.
9. Search the archive and confirm filters and paging work.
10. Build a prompt in `prompt_builder.php`, paste a ChatGPT response, and verify the final script cache reloads correctly.
11. Toggle dark mode and print view to make sure browser preferences still persist.

## Endpoint Checks

If you are debugging a feature directly, these are the most useful endpoints to test:

| Endpoint | Why |
|---|---|
| `copy_item.php` | Confirm latest-row loading for a SKU |
| `lookup_preview.php` | Confirm lookup result shaping and photo metadata |
| `suggestions.php` | Confirm lookup autocomplete |
| `autosave.php` | Confirm draft save and conflict handling |
| `script_cache.php` | Confirm prompt cache reads and writes |
| `upload_photo.php` | Confirm single upload validation |
| `upload_photo_chunk.php` | Confirm chunk assembly |
| `set_thumbnail.php` | Confirm thumbnail selection |
| `update_item.php` | Confirm status and price updates |
| `delete_item.php` | Confirm deletion and archive copy |
| `undo_delete.php` | Confirm soft-delete recovery |
| `verify_now.php` | Confirm backup verification |
| `health.php` | Confirm limits and backup metadata |

## Useful Expectations

- `copy_item.php` should return the newest row for a SKU, not the oldest.
- `autosave.php` should reject empty SKUs and oversized payloads.
- `script_cache.php` should save prompt text, pasted ChatGPT text, and final text together.
- `upload_photo.php` should reject unsupported mime types.
- `upload_photo_chunk.php` should reject bad chunk metadata or missing parts.
- `update_item.php` should only allow status and price updates.
- `delete_item.php` should require the literal confirmation word `DELETE`.

## Debugging Tips

- If a test fails, check whether the database file is writable.
- If photo upload fails, check PHP extensions, file size limits, and the `data/sku_photos/` directory.
- If backup tests fail, check `data/backups/`, PowerShell availability, and the checksum file next to the latest backup.
- If archive tests fail, rebuild `data/archive.sqlite` from the live archive table.
