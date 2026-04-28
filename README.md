# Dispo.Tech Intake Sheet Template

Simple PHP + SQLite intake sheet with autosave, backups, and a dashboarded SKU lookup.

## Run locally

```bash
php -S localhost:8000
```

Then open `http://localhost:8000`.

Smoke test (needs curl extension for photo upload):
```bash
php scripts/smoke.php
```

## Data storage
- Active intake records live in `data/intake.sqlite`.
- Legacy archive rows live in `data/archive.sqlite` and are shown in `archive.php`.
- SKU photos live in `data/sku_photos/<SKU_NORMALIZED>/`; indexed in `sku_photos` table.
- Import DBeaver CSV exports with `php scripts/import_archive_csv.php <csv-path> --source=<label> --table=<legacy_table>`, then rebuild the standalone archive DB with `php scripts/build_archive_db.php`.

## Home & lookup
- `home.php` shows an ops dashboard (totals, today’s count, in-progress vs. sold, latest backup age/size badge), recent activity, quick actions, and a “Run backup now” button (local only).
- Lookup pane: SKU + status filters, quick chips (Intake/Listed/Sold/Stale >7d), live preview with status chips, relative “last updated,” thumbnails, and “Load more” + “Refresh” controls.
- `suggestions.php?q=...` streams live SKU + “What is it?” suggestions; `lookup_preview.php` powers the preview table (accepts `limit`).
- Open `intake.php?clear_draft=1` for a blank intake.
- `prompt_builder.php` builds the ChatGPT prompt and the final eBay script with the boilerplate description block.

## Intake/drafts
- Autosaves while you type (server + local); if you clear via “New Intake,” the last draft is stashed and a “Restore last draft” button appears when the form is empty.
- Save & Duplicate: saves, then pre-fills a new form with prior values except SKU/photos.
- Copy fields from SKU: enter an existing SKU to pull its latest record (excludes SKU/photos).
- SKU is uppercased client-side on submit; SKU and “What is it?” are required (toast + inline errors). “What is it?” has a live length counter (120 max).
- Bulk actions: select rows, choose a status, click “Apply to selected,” or use “Delete selected” (double confirmation).

## Appearance
- Dark mode uses a modern charcoal/indigo palette with glassy cards; toggle in headers (preference stored).
- Print button triggers print styles; optional pink background toggle.

## Backups & safety
  - `scripts/backup.ps1` (retention defaults to **no pruning**) snapshots the DB to `data/backups/` and rotates `logs/lookup.csv` to `logs/archive/`; optional `-CopyTo \\path\\to\\share` mirrors backups off-box.
  - `scripts/verify_backup.ps1` + `scripts/check_db.php` run `PRAGMA integrity_check` on the live DB and newest backup; with `-NotifyAlways` it emails nightly successes (and all failures) when `scripts/alert.config.ps1` is configured from the sample.
  - Hooks: `.githooks/pre-commit` blocks staging DB/backups/logs and runs a backup; `.githooks/pre-push` runs a backup before every push. `core.hooksPath` is set to `.githooks`; on a fresh clone run `git config core.hooksPath .githooks`.
  - Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 0` (run elevated) to chain backup + verify nightly (with success emails).
  - `backup_now.php` provides the local-only “Run backup now” button on Home.

## Maintenance / fixes
- Run `php scripts/migrate.php` to ensure required dirs/tables, set WAL (`journal_mode=WAL`, `synchronous=NORMAL`), and add indexes (`sku_normalized`, `(status, updated_at)`).
- Keep live DBs out of git: `git update-index --skip-worktree data/intake.sqlite data/archive.sqlite` (undo with `--no-skip-worktree`).

## SKU photos
- Drag/drop/paste or click to queue; uploads chunked at 512KB. Download-all as ZIP; `photo.php?id=...` streams individual files; thumbnails surface in lookup preview, recent SKUs, and home activity when available (placeholder shown otherwise).

## Square sync
- Create a local `.env` file in the repo root and set `SQUARE_ACCESS_TOKEN` and `SQUARE_LOCATION_ID` there to enable automatic sync. Start from `.env.example`. Optional: `SQUARE_ENVIRONMENT=sandbox|production`, `SQUARE_API_VERSION=2026-01-22`, `SQUARE_CURRENCY=USD`, `SQUARE_DEFAULT_QUANTITY=1`, `SQUARE_SYNC_ENABLED=0` to force-disable.
- On save, quick price/status edits, bulk status updates, photo upload, and thumbnail changes, pinksheet upserts a Square catalog item + variation, attaches the selected/latest photo when Square supports the image type, and sets inventory count to `1` or `0` when status is `SOLD`.
- Backfill existing SKUs with `php scripts/sync_square.php --all` or sync one SKU with `php scripts/sync_square.php SKU123`.
- Sync metadata and last errors are stored in `square_catalog_sync`; detailed errors are appended to `logs/square_sync.log`.

## Docs
- `docs/usage.md` — core flows, themes, print guidance.
- `docs/schema.md` — intake_items, archive_items, and database notes.
- `docs/maintenance.md` — backups, hooks, alerts, restore steps.
- `docs/dev.md` — file map, run instructions, smoke test.
- `docs/ops.md` — operator SOP: daily/weekly checks, backups, bulk delete safeguards, restore playbook.
- `CHANGELOG.md` — noteworthy UI/ops changes.
