# Start development server through the public/ front controller.
# Everything except public/ is unreachable over HTTP; there is no sign-in.
# For production performance, use nginx + PHP-FPM instead (see docs/ops.md).
Write-Host "Starting PHP dev server on http://127.0.0.1:8765" -ForegroundColor Green
Write-Host "Open http://127.0.0.1:8765 in your browser — no login needed" -ForegroundColor Yellow
php -d upload_max_filesize=32M -d post_max_size=128M -d max_file_uploads=100 `
  -S 127.0.0.1:8765 -t public public/router.php
