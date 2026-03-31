# Dispo.Tech Intake Sheet Template

Simple PHP + SQLite intake sheet with autosave, backups, and a dashboarded SKU lookup.

## Run locally

```bash
php -S localhost:8000
```

Then open `http://localhost:8000`.

## Data storage
- Records live in `data/intake.sqlite`.
- SKU photos live in `data/sku_photos/<SKU_NORMALIZED>/`; indexed in `sku_photos` table.

## Home & lookup
- `home.php` shows an ops dashboard (totals, today’s count, in-progress vs. sold, latest backup age/size badge), recent activity, quick actions, and a “Run backup now” button (local only).
- Lookup pane: SKU + status filters, quick chips (Intake/Listed/Sold/Stale >7d), live preview with status chips, relative “last updated,” thumbnails, and “Load more” + “Refresh” controls.
- `suggestions.php?q=...` streams live SKU + “What is it?” suggestions; `lookup_preview.php` powers the preview table (accepts `limit`).
- Open `index.php?clear_draft=1` for a blank intake.

## Intake/drafts
- Autosaves while you type; if you clear via “New Intake,” the last draft is stashed and a “Restore last draft” button appears when the form is empty.
- SKU is uppercased client-side on submit; SKU and “What is it?” are required (toast + inline errors). “What is it?” has a live length counter (120 max).
- Bulk status: select rows in the intake table, choose a status, click “Apply to selected.”

## Appearance
- Dark mode uses a modern charcoal/indigo palette with glassy cards; toggle in headers (preference stored).
- Print button triggers print styles; optional pink background toggle.

## Backups & safety
- `scripts/backup.ps1` (retention defaults to **no pruning**) snapshots the DB to `data/backups/` and rotates `logs/lookup.csv` to `logs/archive/`; optional `-CopyTo \\path\\to\\share` mirrors backups off-box.
- `scripts/verify_backup.ps1` + `scripts/check_db.php` run `PRAGMA integrity_check` on the live DB and newest backup; can email on failure if you copy `scripts/alert.config.sample.ps1` to `scripts/alert.config.ps1` and fill SMTP.
- Hooks: `.githooks/pre-commit` blocks staging DB/backups/logs and runs a backup; `.githooks/pre-push` runs a backup before every push. `core.hooksPath` is set to `.githooks`; on a fresh clone run `git config core.hooksPath .githooks`.
- Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 0` (run elevated) to chain backup + verify nightly.
- `backup_now.php` provides the local-only “Run backup now” button on Home.

## Maintenance / fixes
- Run `php scripts/migrate.php` to ensure required dirs/tables, set WAL (`journal_mode=WAL`, `synchronous=NORMAL`), and add indexes (`sku_normalized`, `(status, updated_at)`).
- Keep live DB out of git: `git update-index --skip-worktree data/intake.sqlite` (undo with `--no-skip-worktree`).

## SKU photos
- Drag/drop/paste or click to queue; uploads chunked at 512KB. Download-all as ZIP; `photo.php?id=...` streams individual files; thumbnails surface in lookup preview when available.

## Docs
- `docs/usage.md` — core flows, themes, print guidance.
- `docs/schema.md` — intake_items columns and notes.
- `docs/maintenance.md` — backups, hooks, alerts, restore steps.
- `docs/dev.md` — file map, run instructions, smoke test.
- `CHANGELOG.md` — noteworthy UI/ops changes.
