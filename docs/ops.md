# Operator SOP

This is the day-to-day checklist for keeping the app healthy.

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

## Common Commands

```bash
php -S 127.0.0.1:8765 -t .
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
- Square sync failing: check `logs/square_sync.log`, open `square_debug.php` locally to confirm credentials and PHP extensions, then try `sync_square_now.php` for a full re-sync.

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

## Square webhook setup and operation

1. Run `php scripts/migrate.php`.
2. Start the internal service at `http://10.42.0.112:8000`, then configure ngrok to forward there. The Square URL is `https://ASSIGNED-NAME.ngrok-free.app/square_webhook.php`.
3. In Square Developer Console, select Sandbox first, open Webhooks, create a subscription at that URL, and select `payment.updated`, `refund.updated`, `inventory.count.updated`, and `order.updated`.
4. Copy the subscription signature key into `.env`, set the exact URL in `SQUARE_WEBHOOK_NOTIFICATION_URL`, and set `SQUARE_WEBHOOK_ENABLED=1`. Sandbox and Production use separate keys and subscriptions.
5. Send a Square test event and confirm HTTP 200 in Square's webhook log, then check `square_webhook_status.php` from the private network.

```bash
curl -i http://10.42.0.112:8000/square_webhook.php
curl -i -X POST -H "Content-Type: application/json" -d '{"event_id":"local-test","type":"payment.updated"}' http://10.42.0.112:8000/square_webhook.php
php scripts/test_square_webhook.php payment.updated
php scripts/reconcile_square_sales.php --hours=24 --dry-run
```

The first request returns 405; the unsigned POST returns 403. For a failed event, inspect `logs/square_webhook.log` and the private status page, fix the cause, then run `php scripts/reprocess_square_webhook.php EVENT_ID`. Use the reconciliation command without `--dry-run` to recover a missed payment. Refunds move items to `RETURNED - NEEDS INSPECTION`; inspect electronics before making them sellable.

Changing the public ngrok URL requires updating both `SQUARE_WEBHOOK_NOTIFICATION_URL` and the Square subscription. Forwarding the whole port exposes the entire app, so production should place a reverse proxy or dedicated listener in front of Pinksheet and expose only `square_webhook.php`. Rotate a signature key in Square, update `.env`, and restart PHP. Test a real low-value sale only after Sandbox succeeds.
