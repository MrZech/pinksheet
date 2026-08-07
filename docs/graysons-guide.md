# Grayson's Guide

This is the "if I am gone, start here" page.

The goal is simple: someone new should be able to open this repo, understand what the app does, know where the important files live, and recover when something feels weird without having to guess.

## The Short Version

Pinksheet is a PHP + SQLite app for intake, lookup, photos, prompt building, archive search, backup/restore, and optional Square sync.

If you only remember five things:

1. `data/intake.sqlite` is the live database.
2. `index.php` is the main intake workflow.
3. `home.php` is the dashboard and lookup shell.
4. `docs/` explains the system better than any single file comment can.
5. Backups matter more than cleverness.

## What This Repo Is For

The app is used to:

- intake items
- search and review current items
- manage photos for each SKU
- build eBay prompt/script text
- search legacy archive data
- back up and verify the live database
- sync eligible items to Square when configured

This is not a generic framework project. It is a working inventory tool with a few local-only operations that should stay careful and explicit.

## Start Here In Order

If you are new to the repo, read these in order:

1. [Usage](usage.md)
2. [Schema](schema.md)
3. [Developer notes](dev.md)
4. [Testing](testing.md)
5. [Maintenance](maintenance.md)
6. [Operator SOP](ops.md)
7. [Archive workflow](archive.md)
8. [Backup and restore playbook](restore_playbook.md)

That sequence goes from "how the app behaves" to "how to keep it alive."

## Mental Model

Think of the app as four layers:

- The browser UI
- The PHP endpoints
- The SQLite tables
- The on-disk files for photos, logs, backups, and temp chunks

Most bugs happen when one of those layers changes and the others do not get updated with it.

### Live Data

- `data/intake.sqlite` is the source of truth for current work.
- `data/archive.sqlite` is a separate read-only archive database.
- `data/sku_photos/` holds uploaded images on disk.
- `data/backups/` holds copies of the live database and checksum files.
- `logs/` holds lookup, upload, and Square sync logs.

### What To Expect From The Code

- Entry-point PHP files are usually small and direct.
- A lot of behavior is split into helper endpoints.
- Several workflows write to SQLite and also keep related files on disk.
- Some endpoints are intentionally local/private only.

## Main Pages And What They Do

| File | Purpose |
|---|---|
| `index.php` | Main intake page, save/update flow, autosave UI, delete and duplicate actions |
| `intake.php` | Thin wrapper for `index.php` |
| `home.php` | Dashboard, lookup shell, backup controls, recent items |
| `lookup.php` | Thin wrapper for `home.php` |
| `archive.php` | Legacy archive browser |
| `kanban.php` | Status board |
| `prompt_builder.php` | ChatGPT prompt and eBay script builder |
| `photo.php` | Streams stored photos |
| `download_photos.php` | ZIP export for all photos on a SKU |

If you are trying to find behavior, start with these files before chasing helpers.

## The Important Endpoints

These are the files most likely to matter during a real maintenance issue:

- `autosave.php` - server-backed drafts
- `copy_item.php` - load the newest row for a SKU
- `lookup_preview.php` - dashboard and lookup preview data
- `suggestions.php` - lookup autocomplete
- `update_item.php` - status and price edits
- `delete_item.php` - delete with soft-delete backup
- `undo_delete.php` - restore the most recent soft-deleted row
- `upload_photo.php` - single photo uploads
- `upload_photo_chunk.php` - large/chunked photo uploads
- `set_thumbnail.php` - mark one photo as the preview image
- `backup_now.php` - local backup trigger from the UI
- `verify_now.php` - local backup verification trigger from the UI
- `sync_square_now.php` - local-only full Square sync
- `square_debug.php` - Square config and diagnostics

## How Data Moves

### Intake

1. A user opens `index.php` or `intake.php`.
2. The form posts data to the intake save logic.
3. The app normalizes the SKU and saves the row into `intake_items`.
4. The dashboard, lookup view, and kanban board all read from that same live data.

### Autosave

1. The browser sends draft state to `autosave.php`.
2. The server stores versioned JSON in `intake_drafts`.
3. If a newer version already exists, the server returns a conflict instead of overwriting it.

### Photos

1. A photo upload lands in `upload_photo.php` or `upload_photo_chunk.php`.
2. Metadata is written to `sku_photos`.
3. The actual file is stored under `data/sku_photos/<normalized-sku>/`.
4. `photo.php` streams the file back when the UI needs it.

### Prompt Builder

1. `prompt_builder.php` loads the latest item through `copy_item.php`.
2. It reads and writes prompt text through `script_cache.php`.
3. It keeps prompt, pasted ChatGPT output, and final script text together per SKU.

### Square Sync

1. When configured, saves and key updates can push to Square automatically.
2. `square_sync.php` does the sync logic.
3. `square_catalog_sync` stores the last known Square IDs, versions, payload hash, and error state.
4. `logs/square_sync.log` records more detailed sync failures.

## Local Setup

For local work, the repo can be started with:

```bash
php -S 127.0.0.1:8765 -t public public/router.php -d upload_max_filesize=32M -d post_max_size=128M -d max_file_uploads=100
```

Then open:

- `http://127.0.0.1:8765/home.php`
- `http://127.0.0.1:8765/intake.php?clear_draft=1`
- `http://127.0.0.1:8765/archive.php`

If your system PHP is awkward, the bundled PHP runtime lives in `php-8.5.4/`.

## Daily Operating Checklist

If you are the person taking over for the day, this is the rhythm:

1. Open `home.php`.
2. Make sure the dashboard loads.
3. Check whether the backup badge looks recent.
4. Read any alerts or warnings.
5. Open the lookup panel and confirm search works.
6. Open `intake.php?clear_draft=1` and make sure autosave behaves normally.
7. Spot-check one known SKU in lookup, intake, and prompt builder if something feels off.

## Weekly Operating Checklist

1. Run the smoke test.
2. Verify the latest backup.
3. Check disk space in `data/backups/`, `data/sku_photos/`, and `logs/`.
4. Confirm the archive database still matches expectations after import work.
5. Spot-check one item with photos and one item without photos.
6. If Square sync is enabled, review the latest sync log for quiet failures.

## Fast Debug Map

When something breaks, this is usually the shortest path:

- Intake save or editing issue: `index.php`, `update_item.php`, `copy_item.php`
- Autosave problem: `autosave.php`
- Lookup search issue: `lookup_preview.php`, `suggestions.php`
- Missing photos: `upload_photo.php`, `upload_photo_chunk.php`, `photo.php`, `set_thumbnail.php`
- Deleted row recovery: `delete_item.php`, `undo_delete.php`
- Prompt builder weirdness: `prompt_builder.php`, `script_cache.php`
- Backup issue: `backup_now.php`, `verify_now.php`, `scripts/backup.ps1`, `scripts/verify_backup.ps1`
- Archive problem: `archive.php`, `scripts/build_archive_db.php`
- Square issue: `square_sync.php`, `sync_square_now.php`, `square_debug.php`, `logs/square_sync.log`

## Things That Are Easy To Miss

- SKU matching uses the normalized SKU, not the raw string.
- Saved rows update `updated_at`, which is important for newest-row lookups.
- Photos are not stored inside SQLite; they live on disk and are only referenced by metadata rows.
- Deleting a row does not necessarily delete the photo files.
- Backup and restore work is more about safety than speed.
- Some endpoints are meant for localhost or private-network use only.

## What Not To Do Casually

- Do not assume a file on disk is the same thing as a row in SQLite.
- Do not change save behavior without checking lookup, prompt builder, and Square sync side effects.
- Do not touch backup or restore flow without reading the maintenance docs first.
- Do not treat local-only endpoints like public endpoints.
- Do not skip verification after a database or backup change.

## Useful Commands

```bash
php scripts/smoke.php
php scripts/check_db.php data/intake.sqlite
php scripts/build_archive_db.php
```

If you are working on backups or restore behavior, also read:

- [Maintenance](maintenance.md)
- [Backup and restore playbook](restore_playbook.md)

## Handoff Notes For Future Me

If you are reading this after a gap in time, leave the next person a trail with:

- what changed
- which files changed
- whether the live database was touched
- whether backups or archive rebuilds were run
- whether photos or Square sync were part of the change
- any weird edge cases you learned the hard way

The best documentation here is the kind that makes the next person calm.
