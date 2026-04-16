# Developer notes

## File map
- `index.php` — intake form, autosave + draft backup/restore, saves/updates, bulk status, print toggle.
- `home.php` — dashboard cards (counts, backup info), alerts, recent activity, quick actions, dedicated SKU lookup pane.
- `copy_item.php` — returns latest record JSON for a SKU (used by “Copy fields from SKU”; excludes sku/photos/id/timestamps).
- `delete_item.php` — deletes a record by id+sku; supports AJAX and form redirect with status query.
- `scripts/smoke.php` — health, intake page, lookup preview, autosave POST, and photo upload (requires curl extension for the photo step).
- `assets/style.css` — theming (light/dark/pink), dashboard/lookup card styles, print styles.
- `suggestions.php` / `lookup_preview.php` — lookup APIs.
- `kanban.php` — status board with drag-to-update (uses `update_item.php`; keep endpoint local/private).
- `update_item.php` — local-only status/price updater used by lookup inline edits and Kanban.
- `set_thumbnail.php` — local-only endpoint to mark a photo as the thumbnail for a SKU.
- `scripts/backup.ps1` — DB/log backup (no pruning by default).
- `scripts/verify_backup.ps1` + `scripts/check_db.php` — integrity checks and optional email alerts (reads `scripts/alert.config.ps1`).
- `scripts/register_backup_task.ps1` — schedule nightly backup + verify.
- `scripts/migrate.php` — ensures dirs/tables, sets WAL + `synchronous=NORMAL`, adds indexes on `sku_normalized` and `(status, updated_at)`.
- `backup_now.php` — local-only endpoint to trigger `scripts/backup.ps1` (used by the Home “Run backup now” button).
- `.githooks/pre-commit` / `.githooks/pre-push` — run backups; pre-commit blocks DB/backups/logs from staging.

## Run locally
- `php -S localhost:8000` from repo root, open http://localhost:8000.
- Smoke: `php scripts/smoke.php` (run a local server first).

## Coding conventions
- PHP: lightweight, single-file pages; keep inputs trimmed/escaped (see `h()` and `checked()` helpers).
- CSS: variables at top for palettes; print styles in `@media print`; dashboard/lookup share card components.

## Manual smoke test (quick)
1) Load home: cards populate, backup tile shows age, alerts block empty unless issues.  
2) SKU lookup: type 2+ chars, see live preview; hit “Refresh preview” button.  
3) Intake: type, wait for autosave; click “New Intake” then “Restore last draft” to confirm backup/restore.  
4) Save & Duplicate: save once, confirm new form is prefilled (SKU empty), copy-from-SKU works, autosave still runs.  
5) Bulk-select rows, change status; then try “Delete selected” and type DELETE—list should refresh with success message.  
6) Toggle dark mode, toggle print-pink, trigger print preview.

