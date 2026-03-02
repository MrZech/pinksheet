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
