# Data schema

> [!TIP]
> **Docs:** [Dev](dev.md) · [Usage](usage.md) · [Maintenance](maintenance.md)

## intake_items (SQLite)

| Column | Type | Notes |
|--------|------|--------|
| `id` | INTEGER | PK, autoincrement |
| `created_at` | TEXT | ISO timestamp, default now |
| `updated_at` | TEXT | ISO timestamp, default now |
| `sku` | TEXT | Original entry |
| `sku_normalized` | TEXT | Uppercase trimmed SKU (**indexed**) |
| `status` | TEXT | Intake, Description, Tested, Listed, SOLD |
| `what_is_it` | TEXT | Free description |
| `date_received` | TEXT | YYYY-MM-DD |
| `source` | TEXT | |
| `functional` | TEXT | Yes / No / Unknown |
| `condition` | TEXT | |
| `is_square` | INTEGER | Boolean-ish |
| `care_if_square` | INTEGER | Boolean-ish |
| `cords_adapters` | TEXT | |
| `keep_items_together` | TEXT | |
| `picture_taken` | TEXT | |
| `power_on` | TEXT | |
| `brand_model` | TEXT | |
| `ram` | TEXT | |
| `ssd_gb` | TEXT | |
| `cpu` | TEXT | |
| `os` | TEXT | |
| `battery_health` | TEXT | |
| `graphics_card` | TEXT | |
| `screen_resolution` | TEXT | |
| `where_it_goes` | TEXT | |
| `ebay_status` | TEXT | |
| `ebay_price` | REAL | Nullable; kept in sync with dispotech when using unified **Price** in UI |
| `dispotech_price` | REAL | Nullable; same as above |
| `in_ebay_room` | TEXT | |
| `what_box` | TEXT | |
| `notes` | TEXT | |

## archive_items (SQLite)

Read-only historical store for legacy exports and sold inventory. Photos are intentionally not part of this archive. The standalone archive database lives in `data/archive.sqlite`.

| Column | Type | Notes |
|--------|------|--------|
| `id` | INTEGER | PK, autoincrement |
| `created_at` | TEXT | Import/creation timestamp |
| `updated_at` | TEXT | Last known update timestamp |
| `sku` | TEXT | Original SKU from the legacy system |
| `sku_normalized` | TEXT | Uppercase trimmed SKU (**indexed**) |
| `title` | TEXT | Item title or description |
| `status` | TEXT | Legacy status / sold state |
| `sold_at` | TEXT | Sold date if available |
| `sold_price` | REAL | Sale price if available |
| `purchase_price` | REAL | Cost if available |
| `source` | TEXT | Where it came from |
| `buyer` | TEXT | Buyer/customer if available |
| `notes` | TEXT | Legacy notes |
| `legacy_source` | TEXT | File or server label used during import |
| `legacy_table` | TEXT | Original table name from the export |
| `legacy_id` | TEXT | Original row identifier |
| `legacy_payload` | TEXT | Raw row JSON preserved for audit/search |

### Indexes

| Name | Column(s) |
|------|-------------|
| `idx_intake_items_sku_normalized` | `sku_normalized` |
| `idx_archive_items_sku_normalized` | `sku_normalized` |
| `idx_archive_items_status_sold_at` | `status, sold_at` |
| `idx_archive_items_legacy_source` | `legacy_source, legacy_table` |
| `idx_archive_items_legacy_identity` | `legacy_source, legacy_table, legacy_id` |

### Save behavior

> [!NOTE]
> **Upsert:** saves match on **normalized SKU** — new SKU inserts; existing SKU updates the **newest** row for that normalized value.
>
> **Bulk status:** bulk updates only change **`status`** and **`updated_at`** for the selected row IDs.

## Database files

| File | Purpose |
|------|---------|
| `data/intake.sqlite` | Active intake and lookup data |
| `data/archive.sqlite` | Standalone archive database used by `archive.php` |
