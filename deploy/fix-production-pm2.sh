#!/usr/bin/env bash
# Limpia procesos PM2 redundantes o rotos en el VPS (cola duplicada, Reverb si solo usan Pusher).
set -euo pipefail

echo "=== PM2 antes ==="
pm2 list || true

# Cola duplicada: Horizon ya procesa Redis
if pm2 describe jobshour-queue >/dev/null 2>&1; then
  pm2 delete jobshour-queue || true
  echo "Eliminado jobshour-queue (usar solo jobshour-horizon)"
fi

# Reverb en crash loop mientras BROADCAST_DRIVER=pusher
if pm2 describe jobshour-reverb >/dev/null 2>&1; then
  pm2 stop jobshour-reverb || true
  pm2 delete jobshour-reverb || true
  echo "Eliminado jobshour-reverb (broadcast = Pusher Cloud)"
fi

# Frontend: puerto único 3000 vía ecosystem si existe
if [[ -f /var/www/jobshour-web/ecosystem.config.js ]]; then
  cd /var/www/jobshour-web
  pm2 delete jobshour-web 2>/dev/null || true
  pm2 start ecosystem.config.js --env production
  pm2 save
  echo "jobshour-web reiniciado con ecosystem.config.js (PORT 3000)"
else
  pm2 restart jobshour-web --update-env || true
fi

echo "=== PM2 después ==="
pm2 list
pm2 save
