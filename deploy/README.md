# JobsHours – Guía de infraestructura en el VPS

## Arquitectura de procesos

```
Internet (443/80)
    │
    ├── Nginx ──► PHP-FPM (Laravel API)    puerto interno 9000
    │        └► Reverb WebSocket           127.0.0.1:8080 (vía proxy /app/)
    │
    ├── PM2  ──► Next.js (frontend)        127.0.0.1:3000
    │
    └── Supervisor ──► Horizon (colas Redis)
                   └── Reverb (WebSockets)
```

## Primer setup en un VPS limpio

```bash
# 1. Clonar repos
git clone https://github.com/mauriciomorales-svg/jobshour-api  /var/www/jobshour-api
git clone https://github.com/mauriciomorales-svg/jobshour-web  /var/www/jobshour-web

# 2. Instalar Supervisor (si no está)
apt install supervisor

# 3. Instalar PM2 globalmente
npm install -g pm2
pm2 startup  # genera el comando de systemd, ejecútalo

# 4. Configurar Nginx
cp /var/www/jobshour-api/deploy/nginx-api.conf /etc/nginx/sites-available/jobshours-api
cp /var/www/jobshour-web/deploy/nginx-web.conf /etc/nginx/sites-available/jobshours-web
ln -s /etc/nginx/sites-available/jobshours-api /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/jobshours-web /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# 5. Configurar logrotate
cp /var/www/jobshour-api/deploy/logrotate.conf /etc/logrotate.d/jobshours

# 6. Deploy inicial (hace todo el resto)
bash /var/www/jobshour-api/deploy/deploy-all.sh
```

## Deploys rutinarios

```bash
# Deploy completo (API + Web)
bash /var/www/jobshour-api/deploy/deploy-all.sh

# Solo API
bash /var/www/jobshour-api/deploy/deploy.sh

# Solo Web
bash /var/www/jobshour-web/scripts/deploy-on-server.sh
```

## Monitoreo

```bash
supervisorctl status          # Estado Horizon + Reverb
php artisan horizon:status    # Horizon vivo/stopped
pm2 list                      # Estado Next.js
curl http://127.0.0.1/api/v1/health  # Health check completo
tail -f /var/log/jobshours-horizon.log
tail -f /var/log/jobshours-reverb.log
```

## Variables de entorno críticas (producción)

| Variable | Valor en producción |
|---|---|
| `REVERB_HOST` | `127.0.0.1` |
| `REVERB_SCHEME` | `http` (Nginx termina TLS) |
| `BROADCAST_DRIVER` | `reverb` |
| `QUEUE_CONNECTION` | `redis` |
| `GEOFENCE_ENABLED` | `true` |
| `MP_WEBHOOK_SYNC` | `false` |
| `SENTRY_LARAVEL_DSN` | DSN de Sentry |
