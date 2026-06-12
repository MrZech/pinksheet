# QZ Tray Setup Instructions

## 1. Download & Install QZ Tray

Download QZ Tray from https://qz.io/download

Install it on the machine that has the Zebra label printer connected.

## 2. Generate Certificate + Private Key

1. Open **QZ Tray** (the desktop app).
2. Go to **Advanced** → **Site Manager**.
3. Click **Add** and enter your application's origin:
   - Local dev: `http://localhost:8000`
   - Production: `https://your-domain.com`
4. Click **Generate** to create a certificate and private key for that origin.
5. Export the two files into the `qz-signing/` directory:
   - `digital-certificate.txt` — public certificate
   - `private-key.pem` — private key

## 3. Set Allowed Origins (Optional)

By default, the signing endpoint accepts any origin. For production, set a restricted origin in your `.env` file:

```
QZ_ALLOWED_ORIGINS=https://your-domain.com
```

Comma-separate multiple origins if needed:

```
QZ_ALLOWED_ORIGINS=https://app.example.com,https://staging.example.com
```

## 4. Verify Endpoints

With the PHP dev server running, check that both endpoints respond:

```bash
# Should return the certificate text (404 if not configured)
curl http://localhost:8000/api/qz/certificate.php

# Should return a Base64 signature
curl -X POST http://localhost:8000/api/qz/sign.php \
  -H "Content-Type: application/json" \
  -d '{"request":"test signing request"}'
```

## 5. Test Printing

1. Start **QZ Tray** on the client machine.
2. Open the intake form or kanban board in your browser.
3. You should **not** see the "Local Print Engine Offline" badge.
4. Click **Print Sticker** (intake form) or **Print Label** (kanban card).
5. The label will print directly to the Zebra printer — no print dialog.

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| "Local Print Engine Offline" badge shows | QZ Tray not running | Start QZ Tray desktop app |
| "Could not load QZ certificate" | Certificate file missing | Generate cert via Site Manager |
| "QZ signing request failed" | Private key missing or mismatch | Re-export both cert + key from Site Manager |
| No printer found | Zebra driver not installed | Install the printer driver, ensure it appears in system printers |
| Label prints garbled | Wrong label preset | Try "detail" preset for larger labels, "compact" for small stickers |
