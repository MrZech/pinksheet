# Operator SOP (Dispo.Tech Intake)

See also: `docs/restore_playbook.md` for detailed backup/photo restore steps.

## Daily checklist (AM)
1) **Open Home dashboard** (`home.php`):
   - Cards load; counts are non-null.
   - Backup badge is ≤36h; if older, check `data/backups/` and run “Run backup now”.
   - Alerts banner empty; if present, read and act.
2) **SKU lookup sanity**:
   - Type a known SKU (or 2 letters); preview shows status + thumb/placeholder.
   - “Refresh preview” works; “Load more” adds rows.
3) **Intake smoke**:
   - Open `intake.php?clear_draft=1`.
   - Type in a few fields; autosave chip shows “Saved …”.
   - Click “New Intake” then “Restore last draft” to confirm recovery works.

## Weekly checklist
1) **Run automated smoke** (requires local server):  
   ```
   php -S 127.0.0.1:8765 -t .
   php scripts/smoke.php
   ```
   All lines should be `[OK]`; photo upload step needs curl extension.
2) **Backup integrity**: run `scripts/verify_backup.ps1` or `php scripts/check_db.php` against live DB and newest backup; confirm `ok: true`.
3) **Storage**: ensure `data/sku_photos` and `data/backups` have space; clean old temp files if any.

## Bulk delete safety
- Use intake table checkboxes + “Delete selected” only after selecting the correct SKUs.
- Two confirmations: (1) browser confirm, (2) type `DELETE`. If unsure, cancel.
- Deleting records **does not delete photos**; if photos must be removed, handle manually in `data/sku_photos/<SKU>/`.
- Single-row delete also requires double confirm and redirects with a success message.

## Copy/duplicate policy
- **Copy fields from SKU** pulls latest record fields except SKU/photos/ids; always set a new SKU manually.
- **Save & Duplicate** pre-fills a fresh form with the previous values (SKU/photos blank). Review and adjust status/notes before saving.

## Restore / incident playbook
1) **Missing data?** Check `data/intake.sqlite` timestamp; if corrupted, restore the newest backup from `data/backups/` (copy over while server is stopped). Detailed steps: `docs/restore_playbook.md`.
2) **Photos missing?** Locate SKU folder under `data/sku_photos/`; if absent, recover from backup mirror. Detailed steps: `docs/restore_playbook.md`.
3) **Autosave lost?** If the form cleared, click “Restore last draft.” If still missing and SKU exists, use “Copy fields from SKU” to pull the latest saved record.

## Operational commands (local)
- Start server: `php -S 127.0.0.1:8765 -t .`
- Smoke test: `php scripts/smoke.php`
- Backup now (PowerShell): `php backup_now.php` (or press button on Home)
- Verify DB: `php scripts/check_db.php`

## Logging
- Lookup events: `logs/lookup.csv`
- Upload errors: `logs/upload_errors.log`
- Backups: `data/backups/` (latest file is most recent)

## When to escalate
- Backup badge >36h and backup scripts failing.
- Smoke test FAIL on autosave, uploads, or lookup preview.
- Repeated DB lock/permission errors in logs or UI.
