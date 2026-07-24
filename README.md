# Pinksheet / Dispo.Tech Intake

Pinksheet is a PHP + SQLite inventory app for intake work, SKU lookup, photo management, legacy archive search, eBay prompt generation, Square sync, and backup/restore operations.

## Read This In Order
1. [Grayson's Guide](docs/graysons-guide.md)
2. [Usage](docs/usage.md)
3. [Schema](docs/schema.md)
4. [Developer notes](docs/dev.md)
5. [Testing](docs/testing.md)
6. [Maintenance](docs/maintenance.md)
7. [Operator SOP](docs/ops.md)
8. [Archive workflow](docs/archive.md)
9. [Backup and restore playbook](docs/restore_playbook.md)

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
- `square_sync.php`, `sync_square_now.php`, and `scripts/sync_square.php` handle Square catalog sync.

## Data Files

- `data/intake.sqlite` is the live working database.
- `data/archive.sqlite` is the standalone archive database used by `archive.php`.
- `data/sku_photos/` stores uploaded photos on disk.
- `data/chunks/` is temporary storage for chunked uploads.
- `data/backups/` stores database backups and checksum files.
- `logs/` stores lookup, upload, and Square sync logs.

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
- Square sync is optional and only runs when the local environment is configured.

## Intake And Workflow Notes

- Autosave runs while you type and can restore the last draft after a clear.
- Copy fields from SKU loads the latest record for that SKU and excludes photos and database IDs.
- Bulk actions let you select rows, change status, or delete with a double confirmation.

## Backups And Safety

- `scripts/backup.ps1` defaults to no pruning and copies the database into `data/backups/`.
- `scripts/verify_backup.ps1` and `scripts/check_db.php` verify the live database and newest backup.
- `.githooks/pre-commit` and `.githooks/pre-push` are meant to keep live database files and backup files out of git history.
- `backup_now.php` provides the local-only Run backup now button on Home.

## Square Sync

- Create a local `.env` file in the repo root and set `SQUARE_ACCESS_TOKEN` and `SQUARE_LOCATION_ID` to enable sync.
- Optional settings include `SQUARE_ENVIRONMENT`, `SQUARE_API_VERSION`, `SQUARE_CURRENCY`, `SQUARE_DEFAULT_QUANTITY`, and `SQUARE_SYNC_ENABLED`.
- On save, quick edits, photo upload, and thumbnail changes, the app can upsert the matching Square catalog item and variation.
- Square sync metadata and last errors are stored in `square_catalog_sync`.
- Detailed sync errors are appended to `logs/square_sync.log`.

## Square webhooks

Inbound Square sales are optional and disabled by default. Add these local-only `.env` values (never commit the signature key):

```dotenv
SQUARE_WEBHOOK_ENABLED=0
SQUARE_WEBHOOK_SIGNATURE_KEY=
SQUARE_WEBHOOK_NOTIFICATION_URL=
SQUARE_WEBHOOK_MAX_BODY_BYTES=1048576
SQUARE_WEBHOOK_REFUND_STATUS="RETURNED - NEEDS INSPECTION"
```

The exact subscription URL is `https://ASSIGNED-NAME.ngrok-free.app/square_webhook.php`; it must exactly match `SQUARE_WEBHOOK_NOTIFICATION_URL`. The endpoint verifies Square's HMAC signature before parsing the request. Completed payments mark mapped Pinksheet items `SOLD`; refunds move them to the inspection status. See [operations](docs/ops.md) for setup, testing, and recovery.

## Docs

- `docs/graysons-guide.md` - the friendly handoff guide for future maintainers.
- `docs/usage.md` - core flows, themes, print guidance.
- `docs/schema.md` - intake_items, archive_items, Square sync, and database notes.
- `docs/maintenance.md` - backups, hooks, alerts, restore steps.
- `docs/dev.md` - file map, run instructions, smoke test.
- `docs/ops.md` - operator SOP: daily/weekly checks, backups, bulk delete safeguards, restore playbook.
- `CHANGELOG.md` - noteworthy UI and ops changes.
