#!/usr/bin/env bash
# ============================================================
# deploy.sh – Deploy completo JobsHours API en el VPS
# Ejecutar: bash /var/www/jobshour-api/deploy/deploy.sh
# ============================================================
set -euo pipefail

APP_DIR="/var/www/jobshour-api"
LOG_FILE="/var/log/jobshours-deploy.log"
ROLLBACK_COMMIT=""

log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
fail() { log "❌ ERROR: $*"; exit 1; }

cd "$APP_DIR" || fail "No existe $APP_DIR"

# ── Guardar commit actual para posible rollback ───────────────────────────────
ROLLBACK_COMMIT=$(git rev-parse HEAD)
log "Commit actual (rollback target): $ROLLBACK_COMMIT"

# ── 1. Pull ───────────────────────────────────────────────────────────────────
log "=== [1/9] Pull latest code ==="
FIREBASE_JSON="$APP_DIR/storage/firebase/jobshours-firebase-adminsdk-fbsvc-a52be09a7f.json"
FIREBASE_BACKUP="/var/lib/jobshours/firebase-adminsdk.json"
if [[ -f "$FIREBASE_JSON" ]]; then
    mkdir -p /var/lib/jobshours
    cp -a "$FIREBASE_JSON" "$FIREBASE_BACKUP"
    log "Firebase credentials respaldadas en $FIREBASE_BACKUP"
fi
git fetch origin
git reset --hard origin/master
if [[ -f "$FIREBASE_BACKUP" ]]; then
    mkdir -p "$(dirname "$FIREBASE_JSON")"
    cp -a "$FIREBASE_BACKUP" "$FIREBASE_JSON"
    log "Firebase credentials restauradas desde backup"
fi

NEW_COMMIT=$(git rev-parse HEAD)
log "Nuevo commit: $NEW_COMMIT"

# ── 2. Dependencias PHP ───────────────────────────────────────────────────────
log "=== [2/9] Composer install ==="
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ── 3. Migraciones ───────────────────────────────────────────────────────────
log "=== [3/9] Migraciones ==="
php artisan migrate --force

# ── 4. Storage link ──────────────────────────────────────────────────────────
log "=== [4/9] Storage link ==="
php artisan storage:link --force 2>/dev/null || true

# ── 5. Limpiar y optimizar caches ────────────────────────────────────────────
log "=== [5/9] Limpiar y optimizar caches ==="
if [[ -f "$APP_DIR/deploy/sync-pg-password-from-env.php" ]]; then
  log "Alinear contraseña PostgreSQL con .env"
  php "$APP_DIR/deploy/sync-pg-password-from-env.php" || log "⚠️  sync-pg-password falló"
fi
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
rm -f bootstrap/cache/config.php
# No usar config:cache: en este VPS deja DB_PASSWORD vacío y rompe la API (500 en /api/v1/*).
php artisan view:cache 2>/dev/null || true

# ── 6. Reiniciar queue workers (Horizon recarga el config) ───────────────────
log "=== [6/9] Reiniciar queue workers ==="
php artisan queue:restart
php artisan horizon:terminate 2>/dev/null || true

# ── 7. Supervisor: instalar/actualizar configs y reiniciar procesos ───────────
log "=== [7/9] Supervisor ==="
SUPERVISOR_CONF_DIR="/etc/supervisor/conf.d"
if command -v supervisorctl >/dev/null 2>&1 && mkdir -p "$SUPERVISOR_CONF_DIR" 2>/dev/null && [[ -d "$SUPERVISOR_CONF_DIR" ]]; then
    cp "$APP_DIR/deploy/supervisor-horizon.conf"      "$SUPERVISOR_CONF_DIR/jobshours-horizon.conf"
    cp "$APP_DIR/deploy/supervisor-reverb.conf"       "$SUPERVISOR_CONF_DIR/jobshours-reverb.conf"
    cp "$APP_DIR/deploy/supervisor-queue-worker.conf" "$SUPERVISOR_CONF_DIR/jobshours-worker.conf"
    supervisorctl reread
    supervisorctl update
    supervisorctl restart jobshours-horizon || true
    supervisorctl restart jobshours-reverb  || true
else
    log "⚠️  Supervisor no disponible; omitiendo reinicio de Horizon/Reverb"
fi

# ── 8. Reload Nginx (sin downtime) ───────────────────────────────────────────
log "=== [8/9] Nginx reload ==="
nginx -t && systemctl reload nginx

# ── 9. Health check ──────────────────────────────────────────────────────────
log "=== [9/9] Health check ==="
sleep 3

HEALTH_URL="http://127.0.0.1:8095/api/v1/health"
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$HEALTH_URL" || echo "000")

if [[ "$HTTP_STATUS" == "200" ]]; then
    log "✅ Health check OK (HTTP $HTTP_STATUS)"
else
    log "⚠️  Health check retornó HTTP $HTTP_STATUS — verificar manualmente"
    log "   URL probada: $HEALTH_URL"
fi

# ── Estado final ─────────────────────────────────────────────────────────────
log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "✅ Deploy API completado"
log "   Commit: $NEW_COMMIT"
log "   Log:    $LOG_FILE"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
supervisorctl status
php artisan horizon:status 2>/dev/null || true
