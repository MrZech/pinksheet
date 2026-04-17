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

### Indexes

| Name | Column(s) |
|------|-------------|
| `idx_intake_items_sku_normalized` | `sku_normalized` |

### Save behavior

> [!NOTE]
> **Upsert:** saves match on **normalized SKU** — new SKU inserts; existing SKU updates the **newest** row for that normalized value.
>
> **Bulk status:** bulk updates only change **`status`** and **`updated_at`** for the selected row IDs.
