# Start development server through the public/ front controller.
# Everything except public/ is unreachable over HTTP; there is no sign-in.
# For production performance, use nginx + PHP-FPM instead (see docs/ops.md).
Write-Host "Starting PHP dev server on http://127.0.0.1:8765" -ForegroundColor Green
Write-Host "Open http://127.0.0.1:8765 in your browser - no login needed" -ForegroundColor Yellow
# opcache + Windows ASLR: on this machine `php -S` dies at startup with
# "Opcode handlers are unusable due to ASLR" (opcache can't map its handler
# table when the CLI server boots). A file-based cache with fallback keeps
# opcache enabled AND survives ASLR — verified this exact combo starts clean.
$OpcacheDir = Join-Path $env:TEMP 'pinksheet-opcache'
if (-not (Test-Path $OpcacheDir)) { New-Item -ItemType Directory -Path $OpcacheDir | Out-Null }
php -d upload_max_filesize=32M -d post_max_size=128M -d max_file_uploads=100 `
  -d opcache.file_cache="$OpcacheDir" -d opcache.file_cache_fallback=1 `
  -S 127.0.0.1:8765 -t public public/router.php
