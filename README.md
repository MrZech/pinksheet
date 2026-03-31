# Dispo.Tech Intake Sheet Template

Simple PHP + SQLite intake sheet with autosave, backups, and SKU lookup.

## Run locally

```bash
php -S localhost:8000
```

Then open `http://localhost:8000`.

## Data storage
- Records live in `data/intake.sqlite`.
- SKU photos live in `data/sku_photos/<SKU_NORMALIZED>/`; indexed in `sku_photos` table.

## Home & lookup
- `home.php` shows an ops dashboard (totals, today’s count, in-progress vs. sold, latest backup age/size), recent activity, quick actions, and a dedicated lookup pane.
- `suggestions.php?q=...` streams live SKU + “What is it?” suggestions; `lookup_preview.php` powers the live results table.
- Open `index.php?clear_draft=1` for a blank intake.

## Intake/drafts
- Intake autosaves locally while you type. If you clear via “New Intake,” the last draft is stashed; a “Restore last draft” button appears when the form is empty to bring it back.
- Bulk status: select rows in the intake table, choose a status, click “Apply to selected.”

## Appearance
- Dark mode toggle in headers (preference stored).
- Print button triggers print styles; optional pink background toggle.

## Backups & safety
- `scripts/backup.ps1` (retention defaults to **no pruning**) snapshots the DB to `data/backups/` and rotates `logs/lookup.csv` to `logs/archive/`.
- `scripts/verify_backup.ps1` + `scripts/check_db.php` run `PRAGMA integrity_check` on the live DB and newest backup; can email on failure if you copy `scripts/alert.config.sample.ps1` to `scripts/alert.config.ps1` and fill SMTP.
- Hooks: `.githooks/pre-commit` blocks staging DB/backups/logs and runs a backup; `.githooks/pre-push` runs a backup before every push. `core.hooksPath` is set to `.githooks`; on a fresh clone run `git config core.hooksPath .githooks`.
- Scheduled task helper: `scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 0` (run elevated) to chain backup + verify nightly.

## Maintenance / fixes
- Run `php scripts/migrate.php` to ensure required directories/tables.
- Keep live DB out of git: `git update-index --skip-worktree data/intake.sqlite` (undo with `--no-skip-worktree`).

## SKU photos
- Drag/drop/paste or click to queue; uploads chunked at 512KB. Download-all as ZIP; `photo.php?id=...` streams individual files.

## Docs
- `docs/usage.md` — core flows, themes, print guidance.
- `docs/schema.md` — intake_items columns and notes.
- `docs/maintenance.md` — backups, hooks, alerts, restore steps.
- `docs/dev.md` — file map, run instructions, smoke test.
- `CHANGELOG.md` — noteworthy UI/ops changes.
