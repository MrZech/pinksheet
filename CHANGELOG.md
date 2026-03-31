# Changelog

## 2026-03-31
- Home redesigned into ops dashboard (metrics, backup tile, recent activity, quick actions) plus two-pane SKU lookup with refresh control.
- Intake autosave now keeps a backup; “Restore last draft” button appears after clearing to recover work.
- Added backup integrity alerts (`verify_backup.ps1` + `alert.config.sample.ps1`) and pre-push backup hook; pre-commit blocks staging DB/backups/logs.
- Added documentation updates and developer file map; hooks/hooksPath notes.

## 2026-03-19
- Light theme retuned to deeper pink (#eaaed6) with balanced gradients and softer contrasts.
- Print styles forced to monochrome text; optional pink background toggle kept.
- Added favicon matching the palette.
- Added scheduled task helper `scripts/register_backup_task.ps1` for nightly backups and log rotation.
- Documentation scaffolding: usage, schema, maintenance, dev notes.
