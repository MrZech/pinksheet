# Operator SOP

This is the day-to-day checklist for keeping the app healthy.

## Access

- There is **no sign-in** — the app works for anyone who can reach the server.
- The document root is `public/`; the front controller `public/router.php` blocks access to `data/`, `logs/`, `.env`, `qz-signing/`, `scripts/`, and the bundled PHP runtime.
- The Square webhook receiver (`webhooks/square.php`) is reachable but still verifies the Square HMAC signature.
- Keep the app on a trusted LAN. If it must be internet-facing, put HTTPS + basic auth in front of it (see README).

## Before Pushing To Production

Run through this checklist on the deployment target before going live:

1. **Square environment** — `.env` must set `SQUARE_ENVIRONMENT=production` and
   `SQUARE_ACCESS_TOKEN` to a production token, not the sandbox values. After
   pasting values, run `php scripts/check_square_env.php` — it reports every
   setting and tests the token against the production API.
2. **Webhook URL** — `SQUARE_WEBHOOK_NOTIFICATION_URL` must be the exact HTTPS URL
   registered in the Square dashboard (e.g. `https://your-domain.com/webhooks/square.php`).
   The signature check is computed against this string, so a mismatch rejects every
   event. Square refuses HTTP delivery, so the URL must be HTTPS and reachable from
   Square's servers (open the firewall).
3. **QZ origin** — `QZ_ALLOWED_ORIGINS` must list the exact deployed origin(s) that
   use QZ Tray signing; the default is `http://127.0.0.1:8765`, which rejects printing
   from any other origin. Unset it falls back to `*` (any origin), which is fine only
   on a trusted LAN.
4. **HTTPS in front** — if the app is reachable beyond the LAN, put HTTPS + basic auth
   in front. There is no login; the router only blocks sensitive paths.
5. **Commit everything** — `git status` should be clean except `.env` (gitignored).
   The webhook/reconciliation/queue stack, `public/`, `webhooks/`, and `certs/` must be
   committed and pushed before deployment.
6. **Verify the stack** — run the test suite (`vendor/bin/phpunit`), then start the
   server and run `php scripts/smoke.php`; it should report all OK. Confirm
   `health.php` shows writable storage and no warnings.
7. **Register scheduled tasks** — run `scripts/setup_scheduled_tasks.ps1` once as
   Administrator (sync queue, reconciliation, nightly backup, DB health).
8. **Send a Square test webhook** — trigger `test.webhook` from the Square dashboard
   and confirm `logs/square_sync.log` records it as verified.

## Morning Check

1. Open `home.php`.
2. Confirm the dashboard cards load and counts are not blank.
3. Check the backup badge.
4. Read the alerts area.
5. Open the lookup panel and confirm search still returns recent items.
6. Open `intake.php?clear_draft=1` and confirm autosave works.

## What Good Looks Like

- The backup badge is recent.
- The alert list is empty.
- Lookup preview updates when you type.
- Thumbnails appear when photos exist.
- Autosave reports a saved state instead of a conflict or error.

## Weekly Check

1. Run the smoke test.
2. Verify the latest backup.
3. Confirm the archive database is current after any import work.
4. Check free disk space for `data/backups/`, `data/sku_photos/`, and `logs/`.
5. Spot-check a known SKU in intake, lookup, archive, and the prompt builder.

## Bulk Delete Safety

- Bulk delete is permanent from the live table.
- The UI requires selected rows and the confirmation word `DELETE`.
- Deleted rows are copied into `intake_deleted` first so `undo_delete.php` can recover the most recent one.
- Deleting a record does not delete photo files.

## Photo Safety

- If a SKU loses its photo files, the database rows can still exist.
- If you restore files manually, check that the stored file names still match the database metadata.
- If you need a single thumbnail, use `set_thumbnail.php` or the related UI control.

## Archive Safety

- The archive page is read-only.
- If imported rows are missing, rebuild `data/archive.sqlite`.
- If a legacy CSV import produced duplicates, check the `legacy_source`, `legacy_table`, and `legacy_id` values.

## Nightly Automation

- Run `scripts/setup_scheduled_tasks.ps1` once (as Administrator) to register all scheduled tasks:
  - `Pinksheet-SyncQueue` — sync queue worker, every 2 minutes
  - `Pinksheet-Reconciliation` — Square inventory reconciliation, daily 3:00 AM
  - `PinksheetNightlyBackup` — `backup.ps1` + `verify_backup.ps1`, daily 12:15 AM
  - `Pinksheet-DbHealth` — `check_db.php` integrity check, daily 8:00 AM
- Verify with `Get-ScheduledTask -TaskName Pinksheet-* | Format-Table TaskName,State`.
- Optional email alerts: copy `scripts/alert.config.sample.ps1` to `scripts/alert.config.ps1`, fill in SMTP, then test with `scripts/send_test_email.ps1`.

## Common Commands

```bash
php -S 127.0.0.1:8765 -t public public/router.php -d upload_max_filesize=32M -d post_max_size=128M -d max_file_uploads=100
php scripts/smoke.php
php scripts/check_db.php data/intake.sqlite
php scripts/build_archive_db.php
```

## Incident Triage

- Backup stale: run backup, then verify the newest backup.
- Autosave broken: test `autosave.php` directly and confirm the SKU is present.
- Lookup broken: test `lookup_preview.php` and `suggestions.php`.
- Photos missing: check `photo.php`, `upload_photo.php`, and the `data/sku_photos/` folder.
- Kanban move broken: test `update_item.php`.
- Square sync failing: check `logs/square_sync.log`, confirm `.env` Square credentials/API version/webhook URL, then try `sync_square_now.php` for a full re-sync from a local/private host. The diagnostic `square_debug.php` is quarantined under `_quarantine/` and is not a live endpoint.

## Escalation Triggers

- The database fails integrity checks.
- The backup script fails repeatedly.
- The archive database will not rebuild.
- File permissions prevent writes to `data/` or `logs/`.
- A local-only endpoint is somehow reachable from outside the private network.

## Notes For The Next Shift

- Record the backup status you saw.
- Record any manual restore or repair work.
- Record whether photos, archive rows, or drafts needed recovery.
- If you changed retention or mirror settings, leave a note about why.

## Square Webhook Setup And Operation

1. In the Square Developer Dashboard, open your app -> Webhooks and add a
   subscription at `https://your-domain.com/webhooks/square.php`. Enable the event
   types listed at the top of `webhooks/square.php` (order.*, payment.*,
   inventory.count.updated, catalog.version.updated).
2. Copy the subscription's Signature Key into `.env` as
   `SQUARE_WEBHOOK_SIGNATURE_KEY` and set `SQUARE_WEBHOOK_NOTIFICATION_URL` to the
   exact URL registered above. The HMAC check is computed against that exact
   string, so a mismatch rejects every event. `SQUARE_WEBHOOK_MAX_AGE_SECONDS`
   sets the replay window.
3. Square only delivers to HTTPS, so the URL must be public, HTTPS, and reachable
   from Square's servers (a reverse proxy in front of Pinksheet is recommended).
   The receiver is the only endpoint Square hits and it still verifies the
   signature and replay window.
4. Send a `test.webhook` from the dashboard and confirm `logs/square_sync.log`
   records it as verified (the receiver answers 200 to test events).
5. Local HMAC sanity check: `php tmp/webhook_hmac_test.php`.
6. Processing is queued: the sync queue worker runs every 2 minutes
   (`scripts/process_sync_queue.php`), reconciliation runs daily at 3:00 AM
   (`scripts/reconcile_square.php`). Queue and audit status are visible at
   `square_queue.php` and `square_status.php`.
7. Rotate a signature key in Square, update `.env`, and restart PHP. Test with
   Sandbox first, then a real low-value sale.
