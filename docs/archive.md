# Archive Workflow

The archive is for historical records that are no longer part of the active intake workflow.

## What The Archive Is

- `archive.php` is the read-only browser.
- `archive_items` is the table that stores legacy history rows.
- `data/archive.sqlite` is the standalone archive database used by the page.
- `data/intake.sqlite` can also contain the live archive table until the standalone database is rebuilt.

## What Belongs Here

- Sold inventory
- Legacy purchase history
- Historical buyer or source notes
- Imported records from older systems that still need to be searchable

## What Does Not Belong Here

- Active intake work
- Photo files
- Records that should still be edited day to day

## How The Page Works

`archive.php` loads the archive database and builds filters for:

- Search text
- Status
- Source
- Legacy source
- Sold date range
- Pagination

The search term is matched against SKU, title, status, source, buyer, notes, and the raw legacy payload.

## Importing CSV Exports

Use the importer when you export a legacy table from DBeaver or another system.

```bash
php scripts/import_archive_csv.php "C:\path\to\legacy_export.csv" --source="Old Server Name" --table="old_table_name"
```

### Import Behavior

- Common column names are normalized automatically.
- Missing status values default to `Archived`.
- The raw CSV row is preserved in `legacy_payload`.
- Duplicate rows are skipped when `legacy_source`, `legacy_table`, and `legacy_id` match an existing row.
- The import writes into the live archive table inside `data/intake.sqlite`.

## Rebuilding The Standalone Archive DB

After importing or restoring archive data, rebuild the standalone database:

```bash
php scripts/build_archive_db.php
```

This copies the live archive table into `data/archive.sqlite`, which is the file `archive.php` prefers.

## Useful Legacy Fields

- `legacy_source` records where the import came from.
- `legacy_table` records the old table name.
- `legacy_id` records the original row identifier.
- `legacy_location_id` and `legacy_category_id` are preserved when present.
- `legacy_payload` keeps the original row JSON for auditing and troubleshooting.

## Example Search Patterns

- SKU only
- Buyer name
- Old source name
- Sold date window
- Raw legacy row content

## Troubleshooting

- If the archive page is empty, check whether `data/archive.sqlite` exists.
- If the archive page is stale, rebuild `data/archive.sqlite`.
- If search results look wrong, inspect `legacy_payload` for the original CSV data.
- If the archive database will not open, run the migration helper and rebuild it again.
