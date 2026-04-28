# Pinksheet / Dispo.Tech Intake

Pinksheet is a PHP + SQLite inventory app for intake work, SKU lookup, photo management, legacy archive search, eBay prompt generation, and backup/restore operations.

## Read This In Order
1. [Usage](docs/usage.md)
2. [Schema](docs/schema.md)
3. [Developer notes](docs/dev.md)
4. [Testing](docs/testing.md)
5. [Maintenance](docs/maintenance.md)
6. [Operator SOP](docs/ops.md)
7. [Archive workflow](docs/archive.md)
8. [Backup and restore playbook](docs/restore_playbook.md)

## Quick Start

```bash
php -S localhost:8000 -t .
```

Then open:

- `http://localhost:8000/home.php` for the dashboard
- `http://localhost:8000/intake.php?clear_draft=1` for a blank intake form
- `http://localhost:8000/archive.php` for the legacy archive

## Main Pages

- `index.php` and `intake.php` provide the intake sheet.
- `home.php` and `lookup.php` load the same dashboard shell; `lookup.php` is the lookup-focused entry point.
- `archive.php` shows the historical archive database.
- `kanban.php` shows the status board.
- `prompt_builder.php` builds the ChatGPT prompt and final eBay script.
- `photo.php`, `download_photos.php`, `upload_photo.php`, `upload_photo_chunk.php`, and `set_thumbnail.php` handle photo storage and delivery.

## Data Files

- `data/intake.sqlite` is the live working database.
- `data/archive.sqlite` is the standalone archive database used by `archive.php`.
- `data/sku_photos/` stores uploaded photos on disk.
- `data/chunks/` is temporary storage for chunked uploads.
- `data/backups/` stores database backups and checksum files.
- `logs/` stores lookup and upload logs.

## Maintenance Commands

```bash
php scripts/migrate.php
php scripts/smoke.php
php scripts/build_archive_db.php
php scripts/check_db.php data/intake.sqlite
```

PowerShell backup and verify helpers live in `scripts/`.

## What To Expect

- Records are matched by normalized SKU.
- Autosave is server-backed and versioned.
- Photos are stored separately from the SQLite rows.
- Archive rows are read-only in the UI.
- Backups are designed to be local-first, with optional mirrors.
