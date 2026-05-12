#!/usr/bin/env bash
# ============================================================
# deploy-all.sh – Despliega API + Web en una sola ejecución
# Ejecutar: bash /var/www/jobshour-api/deploy/deploy-all.sh
# ============================================================
set -euo pipefail

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

log "🚀 Iniciando deploy completo JobsHours (API + Web)"
log ""

log "──── API ────────────────────────────────────────────────"
bash /var/www/jobshour-api/deploy/deploy.sh

log ""
log "──── WEB ────────────────────────────────────────────────"
bash /var/www/jobshour-web/scripts/deploy-on-server.sh

log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "✅ Deploy completo finalizado"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
