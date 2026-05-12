#!/bin/bash
# Deploy completo para JobsHours API en el VPS
# Ejecutar como: bash /var/www/jobshour-api/deploy/deploy.sh

set -e
cd /var/www/jobshour-api

echo "=== [1/7] Pull latest code ==="
git pull origin master

echo "=== [2/7] Install dependencies ==="
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "=== [3/7] Run migrations ==="
php artisan migrate --force

echo "=== [4/7] Clear caches ==="
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "=== [5/7] Setup Supervisor configs ==="
cp /var/www/jobshour-api/deploy/supervisor-horizon.conf /etc/supervisor/conf.d/jobshours-horizon.conf
cp /var/www/jobshour-api/deploy/supervisor-reverb.conf /etc/supervisor/conf.d/jobshours-reverb.conf
supervisorctl reread
supervisorctl update

echo "=== [6/7] Restart Horizon + Reverb ==="
supervisorctl restart jobshours-horizon
supervisorctl restart jobshours-reverb

echo "=== [7/7] Status ==="
supervisorctl status
php artisan horizon:status

echo ""
echo "✅ Deploy completado. Recuerda agregar SENTRY_LARAVEL_DSN y NEXT_PUBLIC_SENTRY_DSN al .env"
