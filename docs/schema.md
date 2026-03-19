# Data schema

## intake_items (SQLite)
- `id` INTEGER PK AUTOINCREMENT
- `created_at` TEXT ISO timestamp (default now)
- `updated_at` TEXT ISO timestamp (default now)
- `sku` TEXT original entry
- `sku_normalized` TEXT uppercase trimmed SKU (indexed)
- `status` TEXT one of: Intake, Description, Tested, Listed, SOLD
- `what_is_it` TEXT free description
- `date_received` TEXT (YYYY-MM-DD)
- `source` TEXT
- `functional` TEXT (Yes/No/Unknown)
- `condition` TEXT
- `is_square` INTEGER boolean-ish
- `care_if_square` INTEGER boolean-ish
- `cords_adapters` TEXT
- `keep_items_together` TEXT
- `picture_taken` TEXT
- `power_on` TEXT
- `brand_model` TEXT
- `ram` TEXT
- `ssd_gb` TEXT
- `cpu` TEXT
- `os` TEXT
- `battery_health` TEXT
- `graphics_card` TEXT
- `screen_resolution` TEXT
- `where_it_goes` TEXT
- `ebay_status` TEXT
- `ebay_price` REAL nullable
- `dispotech_price` REAL nullable
- `in_ebay_room` TEXT
- `what_box` TEXT
- `notes` TEXT

Indexes:
- `idx_intake_items_sku_normalized` on `sku_normalized`.

Notes:
- Saves upsert by normalized SKU: new SKU inserts, existing SKU updates newest row.
- Bulk updates only touch `status` and `updated_at` for selected IDs.
