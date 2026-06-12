# QZ signing files

Place these two files in this directory:

- `digital-certificate.txt`
- `private-key.pem`

They are generated from **QZ Tray > Advanced > Site Manager > + > Create New**.

The private key is ignored by Git and must never be placed in `public/` or sent to the browser.

## Quick start

1. Open QZ Tray > Advanced > Site Manager
2. Click **+** (Create New)
3. Copy the certificate and private key into these two files
4. Restart QZ Tray

The signing endpoints (`qz_certificate.php`, `qz_sign.php`) read from this directory automatically.
