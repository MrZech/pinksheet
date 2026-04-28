# Data Schema

This app keeps live work in `data/intake.sqlite` and builds a separate archive database in `data/archive.sqlite`.

Several support tables are created lazily by the feature that uses them, so a fresh database may not contain every table until the app has touched that workflow at least once.

## Storage Layout

| Path | Purpose |
|---|---|
| `data/intake.sqlite` | Live intake records, drafts, photos metadata, soft deletes, and prompt cache |
| `data/archive.sqlite` | Standalone read-only archive database used by `archive.php` |
| `data/sku_photos/` | Photo files stored by normalized SKU |
| `data/chunks/` | Temporary staging area for chunked uploads |
| `data/backups/` | Backup copies of `data/intake.sqlite` and checksum files |

## `intake_items`

This is the main working table. Intake rows are matched by `sku_normalized`, not by the raw SKU string.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER | Primary key |
| `created_at` | TEXT | Creation timestamp |
| `updated_at` | TEXT | Last write timestamp |
| `sku` | TEXT | Raw SKU as entered |
| `sku_normalized` | TEXT | Uppercase trimmed SKU used for matching |
| `status` | TEXT | `Intake`, `Description`, `Tested`, `Listed`, or `SOLD` |
| `what_is_it` | TEXT | Short item description |
| `date_received` | TEXT | Date received |
| `source` | TEXT | Source or intake origin |
| `functional` | TEXT | Functional status |
| `condition` | TEXT | Condition notes |
| `is_square` | INTEGER | Square-item flag |
| `care_if_square` | INTEGER | Square-item handling note |
| `cords_adapters` | TEXT | Included cords/adapters |
| `keep_items_together` | TEXT | Grouping note |
| `picture_taken` | TEXT | Photo status |
| `power_on` | TEXT | Power-on note |
| `brand_model` | TEXT | Brand/model |
| `ram` | TEXT | RAM |
| `ssd_gb` | TEXT | SSD size |
| `cpu` | TEXT | CPU |
| `os` | TEXT | Operating system |
| `battery_health` | TEXT | Battery note |
| `graphics_card` | TEXT | Graphics info |
| `screen_resolution` | TEXT | Display resolution |
| `where_it_goes` | TEXT | Storage or destination note |
| `ebay_status` | TEXT | eBay workflow note |
| `ebay_price` | REAL | eBay price |
| `dispotech_price` | REAL | Internal price field |
| `in_ebay_room` | TEXT | Room/location note |
| `what_box` | TEXT | Box or bin reference |
| `notes` | TEXT | Free-form notes |

### How `intake_items` is used

- `index.php` writes here.
- `copy_item.php` reads the newest row for a SKU from here.
- `lookup_preview.php`, `suggestions.php`, and `home.php` read from here.
- `kanban.php` reads and updates `status` here.
- `update_item.php` writes `status` or price values here.
- `delete_item.php` archives deleted rows into `intake_deleted` before removing them from here.

## `intake_drafts`

This table stores server-backed autosave drafts.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER | Primary key |
| `sku_normalized` | TEXT | Unique draft key |
| `payload` | TEXT | JSON-encoded form state |
| `version` | INTEGER | Version counter for conflict detection |
| `updated_at` | TEXT | Last saved timestamp |

### How `intake_drafts` is used

- `autosave.php` reads and writes this table.
- The browser sends the current draft version so the server can reject stale overwrites.

## `sku_photos`

This table stores photo metadata. The binary files themselves live on disk.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER | Primary key |
| `sku_normalized` | TEXT | SKU bucket |
| `original_name` | TEXT | Sanitized original file name |
| `stored_name` | TEXT | Random on-disk file name |
| `mime_type` | TEXT | File type returned to the browser |
| `file_size` | INTEGER | Stored size in bytes |
| `created_at` | TEXT | Upload timestamp |
| `is_thumb` | INTEGER | Thumbnail marker used by lookup and home previews; added on demand by the thumbnail workflow |

### How `sku_photos` is used

- `upload_photo.php` and `upload_photo_chunk.php` insert rows here.
- `photo.php` uses the row to locate and stream the file from disk.
- `download_photos.php` uses the rows to build a ZIP for one SKU.
- `set_thumbnail.php` flips `is_thumb` for a SKU so previews prefer that image.

## `script_cache`

This table caches the eBay prompt builder state per SKU.

| Column | Type | Notes |
|---|---|---|
| `sku_normalized` | TEXT | Primary key |
| `sku_display` | TEXT | Display version of the SKU |
| `prompt_text` | TEXT | Generated ChatGPT prompt |
| `chatgpt_text` | TEXT | Pasted ChatGPT response |
| `final_text` | TEXT | Final eBay listing script |
| `updated_at` | TEXT | Last save timestamp |

### How `script_cache` is used

- `prompt_builder.php` loads and saves this cache through `script_cache.php`.
- The cache lets the builder reopen with the last prompt and final text intact.

## `intake_deleted`

This is the soft-delete archive used by `delete_item.php` and `undo_delete.php`.

| Column | Type | Notes |
|---|---|---|
| All `intake_items` columns | various | Copied from the live row before deletion |
| `deleted_at` | TEXT | Timestamp for the deletion event |

### How `intake_deleted` is used

- `delete_item.php` stores the deleted row here before removing it from `intake_items`.
- `undo_delete.php` restores the most recent row from this table.

## `archive_items`

The app uses this table for legacy history rows imported from CSV exports. It exists in both `data/intake.sqlite` and `data/archive.sqlite`.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER | Primary key |
| `created_at` | TEXT | Imported or original creation time |
| `updated_at` | TEXT | Last known update time |
| `sku` | TEXT | Original legacy SKU |
| `sku_normalized` | TEXT | Matching key |
| `title` | TEXT | Title or summary |
| `status` | TEXT | Legacy status |
| `sold_at` | TEXT | Sold date |
| `sold_price` | REAL | Sale price |
| `purchase_price` | REAL | Cost |
| `source` | TEXT | Item source |
| `buyer` | TEXT | Buyer or customer |
| `notes` | TEXT | Notes from the legacy system |
| `legacy_source` | TEXT | Import source label |
| `legacy_table` | TEXT | Original table name |
| `legacy_id` | TEXT | Original row id |
| `legacy_location_id` | TEXT | Optional location id from imports |
| `legacy_category_id` | TEXT | Optional category id from imports |
| `legacy_payload` | TEXT | Raw row JSON preserved for audit and troubleshooting |

### How `archive_items` is used

- `scripts/import_archive_csv.php` imports CSV rows into the live database table.
- `scripts/build_archive_db.php` copies that table into `data/archive.sqlite`.
- `archive.php` searches the standalone archive database first and falls back to the live database if needed.

## Indexes

| Index | Purpose |
|---|---|
| `idx_intake_items_sku_normalized` | Fast SKU lookups in the live table |
| `idx_intake_items_status_updated` | Fast recent-by-status reads |
| `idx_intake_drafts_sku` | One draft per normalized SKU |
| `idx_sku_photos_sku_normalized` | Photo lookup by SKU |
| `idx_script_cache_updated_at` | Cache maintenance and recency sorting |
| `idx_archive_items_sku_normalized` | Archive SKU lookup |
| `idx_archive_items_status_sold_at` | Archive date and status filtering |
| `idx_archive_items_legacy_source` | Archive source filtering |
| `idx_archive_items_legacy_identity` | Prevent duplicate imported legacy rows |

## Save Rules

- Normalized SKU is the primary matching key for intake and draft records.
- Save operations update `updated_at` so the UI can show the freshest work.
- Bulk status updates only change `status` and `updated_at`.
- Price updates write the same value to both price columns so the app stays consistent.
- Photo files are not stored inside SQLite; the database only keeps metadata and file names.
