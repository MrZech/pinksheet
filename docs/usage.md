# Usage Guide

This guide explains how to use the app from the operator point of view and what each major action does under the hood.

## First Things First

- Open `home.php` or `lookup.php` for the dashboard and lookup view.
- Open `intake.php?clear_draft=1` when you want a new blank intake record.
- Use `archive.php` when you need old sold/history data.
- Use `prompt_builder.php` when you want an eBay listing prompt and final script.

## Typical Workflow

1. Create or open an intake record.
2. Fill in the SKU and item details.
3. Let autosave capture the draft while you work.
4. Add photos.
5. Save the record.
6. Move the item through status stages or jump to the Kanban board.
7. Use the lookup and archive pages for later retrieval.

## Intake Sheet

The intake sheet lives in `index.php`, with `intake.php` as a thin wrapper.

- `SKU` is required.
- `What is it?` is required.
- SKU values are normalized to uppercase before the record is saved.
- The status choices are `Intake`, `Description`, `Tested`, `Listed`, and `SOLD`.
- Saving an existing SKU updates the newest matching record for that normalized SKU.

### Save Actions

- `Save Intake Item` writes the current form to SQLite.
- `Save & Duplicate` writes the current form, then opens a fresh form with the same values except SKU and photos.
- If the same SKU already exists in history, a warning appears so you know the save is updating the newest row.

### Drafts And Autosave

- Drafts are saved to `autosave.php` as versioned JSON keyed by normalized SKU.
- Autosave happens while you type.
- If the form is cleared, the app can offer `Restore last draft`.
- If the server has a newer draft than the browser, the app shows a conflict instead of silently overwriting it.

### Copy From SKU

- Enter an existing SKU and use `Copy fields from SKU`.
- The app loads the latest row for that SKU from `copy_item.php`.
- The copied data excludes the database id, SKU, normalized SKU, and timestamps.
- Photos are not copied.

### Delete And Undo

- Single-row delete uses `delete_item.php`.
- Bulk delete also uses `delete_item.php` and requires the word `DELETE` as a second confirmation.
- Deleted rows are written to the `intake_deleted` soft-delete table so `undo_delete.php` can restore the most recent deletion.
- Deleting a row does not delete photo files from `data/sku_photos/`.

## Lookup And Dashboard

The dashboard and lookup view are both served from `home.php`.

- Search suggestions come from `suggestions.php`.
- The live preview table comes from `lookup_preview.php`.
- `lookup.php` is just the lookup entry point and reuses the same page shell.
- You can filter by SKU and status.
- The preview shows status, last updated time, price, thumbnail, and photo count when available.
- Load more and refresh both re-query the preview endpoint.

### What Lookup Does Internally

- Query terms are truncated to the limits reported by `health.php`.
- Status filters only accept the known intake statuses.
- Preview rows are sorted by `updated_at` and `id` descending.
- Thumbnails come from `sku_photos`, preferring rows marked as the thumbnail.

## Photos

Photos are stored separately from the item record.

- `upload_photo.php` handles single-file uploads.
- `upload_photo_chunk.php` handles large chunked uploads.
- `photo.php?id=...` streams one stored image back to the browser.
- `download_photos.php?sku=...` downloads all photos for a SKU as a ZIP.
- `set_thumbnail.php` marks one photo as the thumbnail for a SKU.

### Photo Rules

- Allowed file types are JPG, PNG, WEBP, and GIF.
- Single uploads are limited to 16 MB.
- Chunked uploads support larger files and assemble them from temporary parts in `data/chunks/`.
- Photo files are stored on disk under `data/sku_photos/<normalized-sku>/`.

## Kanban Board

- `kanban.php` shows the same inventory rows as draggable cards.
- Dragging a card between lanes updates `status` through `update_item.php`.
- Inline status changes also call `update_item.php`.
- Price edits use the same endpoint and keep the two price fields in sync.

## Prompt Builder

`prompt_builder.php` turns a SKU record into a ChatGPT-ready prompt and a final eBay listing script.

- It loads the latest row through `copy_item.php`.
- It reads and writes cached prompt text through `script_cache.php`.
- It keeps a prompt, a pasted ChatGPT response, and the final boilerplate script in sync.
- The cache is keyed by normalized SKU.

### Prompt Builder Flow

1. Enter or pick a SKU.
2. Build the ChatGPT prompt from the latest inventory data.
3. Copy that prompt into ChatGPT.
4. Paste the result back into the page.
5. Build the final eBay script.
6. Copy the final script into the listing workflow.

## Archive

- `archive.php` is read-only.
- It searches legacy sold and history rows.
- Filters include search text, status, source, legacy source, and sold date range.
- The page shows the raw imported JSON payload for audit and troubleshooting.
- If `data/archive.sqlite` is missing, the page falls back to the archive table inside `data/intake.sqlite`.

## Theme And Print

- Theme choice is stored in the browser under `themePreference`.
- Dark mode is shared across the app.
- The print button uses `assets/print.css`.
- The print pink toggle only changes print styling.

## Home Dashboard Buttons

- `Run backup now` triggers the backup flow.
- `Verify latest backup` runs the integrity check.
- `New Intake` clears you out to a fresh form.
- `Print` opens the print flow for the current sheet.
- `Dark mode` toggles the theme in the browser.

## Small Things That Matter

- The app trims and uppercases SKU values before using them as keys.
- Most write actions refresh `updated_at` so the newest work is easy to find.
- The lookup and archive pages are designed for search and review, not editing.
- Photos, drafts, prompt cache, and item rows are all stored separately so each part can be restored independently.
