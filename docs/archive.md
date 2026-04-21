# Archive Workflow

Legacy sold/history data lives in the `archive_items` table and is exposed through `archive.php`.

## What belongs here
- Old sold items
- Purchase history
- Legacy customer/source notes
- Anything from previous servers that should remain searchable but not active in intake

## What does not belong here
- Photos
- Active intake work
- Records you still need to edit day-to-day

## Importing a DBeaver CSV export

1. Export the old table from DBeaver as CSV with headers.
2. Run the importer:

```bash
php scripts/import_archive_csv.php "C:\path\to\legacy_export.csv" --source="Old Server Name" --table="old_table_name"
```

3. Open `archive.php` and search by SKU, title, notes, buyer, or legacy ID.

### Example mapping for `inventory_202604211554.csv`

- `inventory_id` -> `legacy_id`
- `item_sku` -> `sku`
- `description` -> `title`
- `location_id` -> `legacy_location_id`
- `ebay_category_id` -> `legacy_category_id`
- `created_at` -> `created_at`
- `updated_at` -> `updated_at`
- Missing status -> defaults to `Archived`

## Import behavior
- Common columns are mapped automatically when their names match obvious variants.
- The raw CSV row is preserved in `legacy_payload` as JSON.
- If `legacy_source`, `legacy_table`, and `legacy_id` match an existing row, the importer skips the duplicate.

## Notes
- The archive is read-only in the UI.
- If your legacy data uses unusual column names, export a small sample first and adjust the importer mapping as needed.
