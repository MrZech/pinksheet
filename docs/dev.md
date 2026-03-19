# Developer notes

## File map
- `index.php` — intake form, saves/updates, bulk status, print toggle.
- `home.php` — SKU/status lookup with live suggestions + preview.
- `assets/style.css` — theming (light/dark/pink), print styles.
- `suggestions.php` / `lookup_preview.php` — lookup APIs.
- `scripts/backup.ps1` — DB/log backup + pruning.
- `scripts/register_backup_task.ps1` — helper to schedule nightly backups.

## Run locally
- `php -S localhost:8000` from repo root, open http://localhost:8000.

## Coding conventions
- PHP: lightweight, single-file pages; keep inputs trimmed/escaped (see `h()` and `checked()` helpers).
- CSS: variables at top for palettes; print styles in `@media print`.

## Manual smoke test (quick)
1) Load home, perform SKU search with 2+ chars, confirm preview updates.  
2) Start new intake, save a SKU.  
3) Update same SKU and confirm it overwrites newest record.  
4) Bulk-select rows, change status, confirm count message.  
5) Toggle dark mode, toggle print-pink, trigger print preview.
