# Changelog

# Changelog

## 2026-05-07
- Intake recent items table: added script status indicator in the Prompt column showing 🟢 Script ready, 🟡 Draft, 🔵 Prompt only, or ⚪ No script based on `script_cache` state; indicator links to the prompt builder for that SKU.
- Dark mode overhaul: shifted background from deep maroon to neutral dark grey (`#0f1117`), surfaces to blue-grey (`#1c2030`), text to clean off-white (`#e8eaf0`), muted text to neutral grey (`#9ba3b5`); reduced radial gradient blobs to one subtle accent; status chips now use semantic green/yellow/red instead of pink tints.
- Dark mode contrast fixes: all ghost buttons now use `!important` overrides to prevent pink gradient bleed-through; ghost buttons in dark mode have a visible white border (`rgba(255,255,255,0.35)`) and frosted background; danger buttons use bright red text and border; hardcoded dark maroon colors on `.dash-value`, `.activity-main`, `.sheet h1`, `.warning`, `.msg.ok`, `.msg.err` now have proper dark mode overrides; print button and toast also fixed.
- Docs: updated `dev.md` file map and request flow for Square sync, `square_debug.php`, `sync_square_now.php`, `config.php`, and new scripts; updated `usage.md` with correct status values, script status indicator section, and Square sync setup guide; added Square sync triage to `ops.md`; added new endpoints and manual checks to `testing.md`.

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
