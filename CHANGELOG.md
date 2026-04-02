# Changelog

# Changelog

## 2026-04-02
- Intake: added Save & Duplicate, copy-fields-from-SKU (excludes SKU/photos), and server-backed autosave/draft restore; toasts now reflect duplicate saves.
- Lists: Recent SKUs and home activity show photo thumbnails (with “No photo” placeholder), single delete with double-confirm, and bulk delete with DELETE confirmation; bulk status update unchanged.
- Lookup preview uses `photo.php` thumbs and home activity now includes thumbs.
- New smoke test script (`scripts/smoke.php`) exercises health, intake page, lookup preview, autosave POST, and photo upload.
- Added `copy_item.php` (fetch latest record JSON) and `delete_item.php` (supports form redirect and AJAX).
- Docs: added operator SOP (`docs/ops.md`) and expanded usage/dev/testing notes for new flows and delete safeguards.

## 2026-03-31
- Home redesigned into ops dashboard (metrics, backup tile, recent activity, quick actions) plus two-pane SKU lookup with refresh control.
- Intake autosave now keeps a backup; “Restore last draft” button appears after clearing to recover work, with counter on “What is it?” and stricter required validation.
- Added backup integrity alerts (`verify_backup.ps1` + `alert.config.sample.ps1`) and pre-push backup hook; pre-commit blocks staging DB/backups/logs.
- Added documentation updates and developer file map; hooks/hooksPath notes.
- Lookup preview gains filter chips, load-more, relative timestamps, and photo thumbnails; dark mode updated to a modern charcoal/indigo palette.
- Backup script can mirror to a share (`-CopyTo`), and `backup_now.php` enables local “Run backup now” from Home.

## 2026-03-19
- Light theme retuned to deeper pink (#eaaed6) with balanced gradients and softer contrasts.
- Print styles forced to monochrome text; optional pink background toggle kept.
- Added favicon matching the palette.
- Added scheduled task helper `scripts/register_backup_task.ps1` for nightly backups and log rotation.
- Documentation scaffolding: usage, schema, maintenance, dev notes.
