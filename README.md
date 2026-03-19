# Dispo.Tech Intake Sheet Template

This is a simple PHP + SQLite intake sheet that mirrors the physical form.

## Run locally

```bash
php -S localhost:8000
```

Then open `http://localhost:8000`.

## Data storage

Records are stored in `data/intake.sqlite`.

## Lookup suggestions

- `home.php` loads recent SKUs via SQLite to seed the lookup datalist for fast matches.
- `suggestions.php?q=...` lets the front-end fetch live SKU/description pairs while the user types, so the dropdown always shows relevant choices.
- `lookup_preview.php` backs the preview table on the home lookup so you can see the 5–7 most recent matches for the typed SKU + status combo before opening the intake sheet.
- The home lookup also surfaces inline guidance/hints and breadcrumb cues so users know they are searching by SKU or status before continuing.
- Appending `?clear_draft=1` when opening `index.php` clears the local draft state so “New Intake” always shows a blank form without leftover presets.

## Appearance

 - The new “Dark mode” toggle in the headers switches the whole UI into a deeper pink-on-charcoal palette while keeping the existing aesthetic, and the preference is remembered per browser session.

## Navigation & logging

 - The hamburger menu now highlights the current section and each page includes breadcrumbs so users can tell where they are before opening a record.
 - Each lookup (SKU/status) writing to `logs/lookup.csv` records timestamp, SKU, status, and source IP for trend analysis.

## Maintenance & health

 - `config.php` centralizes `MAINTENANCE_MODE`, input size limits, and API limits; every endpoint checks this flag so you can temporarily disable the app without editing each file.
 - Both the suggestions and preview APIs cap `q`/`sku` to 50 characters (status to 30 characters) and obey `SUGGESTION_LIMIT`/`PREVIEW_LIMIT` to keep remote use predictable.
 - `health.php` reports the current maintenance state plus the configured length/limit values in JSON, making it easy to hook into a monitoring or uptime probe before exposing the app remotely.
 - Backups: run `powershell -File scripts/backup.ps1` (optionally pass `-RetentionDays 14`) to snapshot `data/intake.sqlite` to `data/backups/` and rotate `logs/lookup.csv` into `logs/archive/`, pruning files older than the retention window. Schedule this nightly via Task Scheduler or cron to keep the DB and logs tidy.

## Bulk status updates

 - Select the checkboxes in the “Recent SKUs” table, choose a new status, and click “Apply to selected”; the server updates those rows and reports how many SKUs moved into the chosen stage.
 - Bulk updates obey the same status list as the intake form, and feedback messages appear above the table so you always know the result.

## Printing

 - Use the new “Print” button in the sheet headers to trigger `window.print()` whenever you want a paper copy; the media styles already hide UI elements like the menu, breadcrumbs, and toast so the output stays clean.
