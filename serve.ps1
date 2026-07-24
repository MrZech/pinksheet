# Start development server with production-like performance
# Requires nginx + PHP-FPM for true speed, but this helps.
Write-Host "Starting PHP dev server on http://127.0.0.1:8765" -ForegroundColor Green
Write-Host "For production performance, use nginx + php-fpm instead" -ForegroundColor Yellow
php -S 127.0.0.1:8765 -t .
