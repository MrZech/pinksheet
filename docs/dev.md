# Developer notes

## File map
- `index.php` — intake form, autosave + draft backup/restore, saves/updates, bulk status, print toggle.
- `home.php` — dashboard cards (counts, backup info), alerts, recent activity, quick actions, dedicated SKU lookup pane.
- `assets/style.css` — theming (light/dark/pink), dashboard/lookup card styles, print styles.
- `suggestions.php` / `lookup_preview.php` — lookup APIs.
- `scripts/backup.ps1` — DB/log backup (no pruning by default).
- `scripts/verify_backup.ps1` + `scripts/check_db.php` — integrity checks and optional email alerts (reads `scripts/alert.config.ps1`).
- `scripts/register_backup_task.ps1` — schedule nightly backup + verify.
- `scripts/migrate.php` — ensures dirs/tables, sets WAL + `synchronous=NORMAL`, adds indexes on `sku_normalized` and `(status, updated_at)`.
- `.githooks/pre-commit` / `.githooks/pre-push` — run backups; pre-commit blocks DB/backups/logs from staging.

## Run locally
- `php -S localhost:8000` from repo root, open http://localhost:8000.

## Coding conventions
- PHP: lightweight, single-file pages; keep inputs trimmed/escaped (see `h()` and `checked()` helpers).
- CSS: variables at top for palettes; print styles in `@media print`; dashboard/lookup share card components.

## Manual smoke test (quick)
1) Load home: cards populate, backup tile shows age, alerts block empty unless issues.  
2) SKU lookup: type 2+ chars, see live preview; hit “Refresh preview” button.  
3) Intake: type, wait for autosave; click “New Intake” then “Restore last draft” to confirm backup/restore.  
4) Save intake twice for same SKU; newest record updates.  
5) Bulk-select rows, change status, confirm count message.  
6) Toggle dark mode, toggle print-pink, trigger print preview.
