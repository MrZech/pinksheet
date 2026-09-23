# Pinksheet production go-live (Square + webhook)

Reference deployment files:

- `pinksheet.service` — systemd unit that runs the PHP app on `127.0.0.1:8765`.
- `nginx-pinksheet.conf` — HTTPS reverse proxy. Publishes **only** the Square
  webhook path; everything else is basic-auth protected.

## The one-time checklist (in order)

### 1. Production credentials (`.env` on the server)

```bash
SQUARE_ENVIRONMENT=production
SQUARE_ACCESS_TOKEN=sq0atp-…          # Production access token (long, NOT the sq0idp- app id)
SQUARE_LOCATION_ID=…                   # your store location id
SQUARE_WEBHOOK_SIGNATURE_KEY=…         # from Webhooks → your subscription
SQUARE_WEBHOOK_NOTIFICATION_URL=https://your-domain.com/webhooks/square.php
SQUARE_WEBHOOK_MAX_AGE_SECONDS=259200
```

Verify with `php scripts/check_square_env.php` — it tests the token against
the production API and reports every setting. It must print `production-ready`.

### 2. Public HTTPS URL

Square delivers webhooks only to public HTTPS URLs. The URL in
`SQUARE_WEBHOOK_NOTIFICATION_URL` **must exactly match** the subscription URL
registered in the Square Dashboard (the app computes its HMAC over that exact
string — any mismatch rejects every event with 401).

- Install `deploy/nginx-pinksheet.conf` (or your own proxy) in front of the app.
- Register `https://your-domain.com/webhooks/square.php` in Square Developer
  Dashboard → your app → **Webhooks → Add subscription**.
- Enable: `order.created`, `order.updated`, `order.completed`,
  `payment.created`, `payment.updated`, `inventory.count.updated`,
  `catalog.version.updated`.
- Copy that subscription's **Signature key** into `.env`.

### 3. Background jobs

The sync queue worker and reconciliation must run on a schedule or queued
Square updates never leave and sales never reconcile:

```bash
bash /opt/pinksheet/scripts/setup_linux_cron.sh    # on the container
```

Registers: sync queue worker every 2 min, nightly reconciliation, backup,
and DB integrity check. (On Windows, run `scripts/setup_scheduled_tasks.ps1`
as Administrator instead.)

### 4. Deploy the code

```bash
git pull --ff-only && systemctl restart pinksheet && systemctl is-active pinksheet
```

### 5. Verify end-to-end

```bash
php scripts/check_square_env.php          # all rows OK / production-ready
php scripts/check_db.php data/intake.sqlite
php scripts/smoke.php                     # needs the app server running
```

Then, in the Square Dashboard → Webhooks → **Send test event**. Watch
`logs/square_sync.log` for `test.webhook … test_ok`. Finally, sell a real
low-value item on the Square register and confirm the mapped SKU moves to
`sold` and a row lands in `sales_history`.

### 6. Live Google Sheet mirror (optional, for the boss)

`scripts/sync_google_sheet.php` pushes the inventory to a Google Sheet on a
schedule, so the boss opens one spreadsheet link and always sees current data
(SKU, status, description, category, qty, prices, last update). The app calls
**out** to Google, so this works even though Pinksheet is LAN-only. Photos are
not pushed — the sheet is tabular data; images stay in the app and the photo
ZIP export.

One-time setup (needs a Google account):

1. [Google Cloud console](https://console.cloud.google.com) → create a
   project → **APIs & Services → Enable APIs** → enable **Google Sheets API**.
2. **APIs & Services → Credentials → Create credentials → Service account**
   → note its e-mail. Then open it → **Keys → Add key → Create new key →
   JSON** → download the file.
3. Create the spreadsheet in Google Sheets (e.g. "Pinksheet Inventory"),
   click **Share**, add the service account e-mail with **Editor** access, and
   copy the spreadsheet id from the URL (the long `1AbC…` between `/d/` and
   `/edit`). Rename the first tab "Inventory" (or set `GOOGLE_SHEET_TAB`).
4. Put the key file on the server (keep it out of git) and add to `.env`:

   ```bash
   GOOGLE_SERVICE_ACCOUNT_FILE=/opt/pinksheet/data/google-sheets-key.json
   GOOGLE_SPREADSHEET_ID=1AbC…
   GOOGLE_SHEET_TAB=Inventory
   ```

5. Install the cron job (re-run the idempotent setup script) and test once:

   ```bash
   bash /opt/pinksheet/scripts/setup_linux_cron.sh
   php /opt/pinksheet/scripts/sync_google_sheet.php
   ```

   Expect `OK: pushed N rows`. The sheet now refreshes every 15 minutes;
   errors land in `logs/cron_sheet_sync.log`. Until configured, the script
   prints a short note and exits 0, so it is safe to leave in cron.

## If Square still reports failed deliveries

Check, in order:

1. `logs/square_sync.log` — look for `invalid signature`, `outside replay
   window`, or the new `notification URL is not a public HTTPS URL` warning.
2. `.env` URL vs. Dashboard subscription URL (must be byte-for-byte equal).
3. The endpoint is reachable from the internet:
   `curl -i https://your-domain.com/webhooks/square.php` (GET returns 405 —
   that is correct and proves routing works).
4. nginx is running and TLS is valid; the app unit is `active`.
