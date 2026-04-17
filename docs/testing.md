# Testing & smoke checklist

> [!TIP]
> **Docs:** [Dev](dev.md) · [Ops](ops.md) · [Maintenance](maintenance.md) · [Usage](usage.md)

## Automated smoke (local)

> [!NOTE]
> The photo upload step needs the **curl** extension; without it, that step is reported as **SKIP** (not a failure of the rest of the suite).

Run a dev server first:

```bash
php -S 127.0.0.1:8765 -t .
```

Then execute:

```bash
php scripts/smoke.php
```
What it hits:
- `/health.php`
- `/intake.php` (intake page)
- `/lookup_preview.php?sku=TEST&limit=3`
- POST `/autosave.php` with a dummy payload
- `/upload_photo.php` with a generated 1x1 PNG (requires curl extension; otherwise marked SKIP)

The script exits non-zero on failure and prints short bodies for debugging.

Interpretation:
- Any FAIL: grab the status code and body from the output, fix, rerun.
- SKIP on photo upload means your PHP lacks curl; install/enable to test uploads.

Run cadence:
- Daily before opening hours if feasible; at minimum weekly or after code changes.

## Manual high-value checks
1) Intake form
   - Autosave shows status chip; “Restore last draft” works after New Intake.
   - Save & Duplicate pre-fills a new form (SKU/photos blank).
   - Copy fields from SKU pulls latest record (except SKU/photos).
2) Photos
   - Upload single photo, see preview; after save, thumb appears in Recent SKUs and Home activity, placeholder shown when absent.
3) Bulk actions
   - Select rows, change status; success count correct.
   - Bulk delete: select rows, click Delete selected, type DELETE; list refreshes with success message.
4) Single delete
   - Delete button in Recent SKUs prompts twice and redirects with a success message.
5) Lookup
   - Type 2+ chars, preview loads with status chip + thumb; load more/refresh still work.
6) Theme/print
   - Dark/light toggle, print button, and print-pink toggle function.
