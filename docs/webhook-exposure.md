# Making the Square Webhook Reachable (Desktop-Only Setup)

Square only delivers webhooks to **public HTTPS URLs**. This machine's app runs on
`http://127.0.0.1:8765`, which Square can never reach. Until the webhook endpoint
is reachable at a public HTTPS URL, **sales will never move items to SOLD via
webhook** (the inventory-pull path can still do it once real credentials are set).

## The three things that must be true at once

1. **Real credentials** in `.env` — `SQUARE_ACCESS_TOKEN`, `SQUARE_LOCATION_ID`
   (currently `replace-with-...` placeholders).
2. **A public HTTPS URL** that tunnels/proxies to this machine's webhook endpoint.
3. **`SQUARE_WEBHOOK_NOTIFICATION_URL` in `.env` must EXACTLY match the URL
   registered in the Square Dashboard.** The receiver computes the HMAC signature
   using this value, so any mismatch rejects every event with 401 "Invalid signature".

The webhook receiver is `/webhooks/square.php` (behind `public/router.php`, which
whitelists entry points). It is HMAC-protected — Square signs every POST and the
app verifies it — so exposing just this path is safe even though the rest of the
app has no login.

## Existing repo asset: `ngrok-webhook-policy.yml`

There is already an ngrok traffic policy in the repo, but it has a bug: it allows
only `/square_webhook.php` while the real endpoint is `/webhooks/square.php`, so
as written it would deny every request (404). Fix the path before using it:

```yaml
on_http_request:
  - expressions:
      - "req.url.path != '/webhooks/square.php'"
    actions:
      - type: deny
        config:
          status_code: 404
```

## Option A — Reverse proxy on a public host (production, recommended)

If the app can be deployed to a small VPS/always-on box (or this machine is
reachable from the internet via port-forwarding):

```nginx
# /etc/nginx/sites-available/pinksheet
server {
    listen 443 ssl;
    server_name your-domain.com;
    # ... SSL cert via certbot ...

    location /webhooks/square.php {
        proxy_pass http://127.0.0.1:8765;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / { return 404; }   # only the webhook path is exposed
}
```

Register `https://your-domain.com/webhooks/square.php` in Square → Webhooks →
Add subscription, and set the same URL in `.env`.

- **Pros:** stable URL, works 24/7 without a tunnel process, no third-party tunnel.
- **Cons:** needs a public host or port-forward + cert setup.

## Option B — ngrok (fastest to try, free tier OK)

1. Install ngrok (`winget install ngrok` or https://ngrok.com/download).
2. `ngrok config add-authtoken <your-token>` (one-time).
3. Start the app server (`.\serve.ps1`), then:

```powershell
ngrok http 8765 --policy ngrok-webhook-policy.yml
```

4. Copy the `https://*.ngrok-free.app` URL it prints, register
   `https://<that-url>/webhooks/square.php` in the Square Dashboard, and set the
   same value in `.env`.

- **Pros:** minutes to set up; the policy file keeps the rest of the app hidden.
- **Cons:** the free-tier URL changes every restart unless you reserve a domain
  (paid); the tunnel must be running whenever Square delivers. Fine for testing
  the end-to-end flow; weak as a permanent solution.

## Option C — Cloudflare Tunnel (free, stable if you own a domain)

```powershell
winget install cloudflare.cloudflared
cloudflared tunnel login
cloudflared tunnel create pinksheet
cloudflared tunnel route dns pinksheet webhooks.your-domain.com
cloudflared tunnel run --url http://127.0.0.1:8765 pinksheet
```

Register `https://webhooks.your-domain.com/webhooks/square.php` in Square, match
it in `.env`. Cloudflare's tunnel only forwards what you route; lock it down with
a WAF rule so only `POST /webhooks/square.php` is reachable.

- **Pros:** stable URL, free, no inbound port-forwarding.
- **Cons:** needs a domain you control; one-time DNS/tunnel setup.

## Recommended sequence for this machine

1. Fix `.env` credentials (token, location).
2. Start with **Option B (ngrok)** with the corrected policy file to prove the
   end-to-end flow: Square test webhook → app logs "test_ok".
3. If the store needs this permanently, move to **Option C or A** so the URL is
   stable and no tunnel process has to babysit it.

## Testing after exposure

- Send a test event from the Square Dashboard → Webhooks → "Send test event".
- Watch `logs/square_sync.log` for `Webhook test.webhook: test_ok`.
- Sell an item on the Square register; confirm the app records it in
  `sales_history` and marks the mapped SKU `sold` (webhook path), and that the
  sync queue worker (every 2 minutes) is running — without it, catalog mappings
  never get created and the sale handler has nothing to match against.

## Risks

- **No login on the app** — expose ONLY `/webhooks/square.php` (the router
  whitelist + a deny-all reverse-proxy/tunnel rule), never the whole app.
- **Tunnel downtime** — Square retries failed deliveries, but a long outage means
  delayed/missed sales; rely on the 2-minute inventory pull as a backstop.
- **URL mismatch** — if `.env` and the Dashboard subscription disagree, every
  event is rejected as "Invalid signature". Keep them identical.
