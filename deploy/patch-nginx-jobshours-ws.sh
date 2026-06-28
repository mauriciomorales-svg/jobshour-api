#!/usr/bin/env bash
# Añade proxy WebSocket Reverb (/app/, /apps/) al vhost jobshours.com si falta.
set -euo pipefail

CONF="/etc/nginx/sites-enabled/jobshour"
MARKER="# --- Reverb WebSocket (JobsHours deploy) ---"

if [[ ! -f "$CONF" ]]; then
  echo "No existe $CONF"
  exit 1
fi

if grep -q "location /app/" "$CONF"; then
  echo "OK: /app/ ya configurado en $CONF"
  exit 0
fi

TMP=$(mktemp)
awk -v marker="$MARKER" '
  /^[[:space:]]*location \/ \{/ && !done {
    print "    " marker
    print "    location /app/ {"
    print "        proxy_pass http://127.0.0.1:8080;"
    print "        proxy_http_version 1.1;"
    print "        proxy_set_header Upgrade $http_upgrade;"
    print "        proxy_set_header Connection \"upgrade\";"
    print "        proxy_set_header Host $host;"
    print "        proxy_set_header X-Real-IP $remote_addr;"
    print "        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;"
    print "        proxy_set_header X-Forwarded-Proto $scheme;"
    print "        proxy_read_timeout 120s;"
    print "    }"
    print "    location /apps/ {"
    print "        proxy_pass http://127.0.0.1:8080;"
    print "        proxy_http_version 1.1;"
    print "        proxy_set_header Host $host;"
    print "        proxy_set_header X-Real-IP $remote_addr;"
    print "        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;"
    print "        proxy_set_header X-Forwarded-Proto $scheme;"
    print "    }"
    done=1
  }
  { print }
' "$CONF" > "$TMP"
mv "$TMP" "$CONF"
nginx -t
systemctl reload nginx
echo "Nginx actualizado: Reverb WS en jobshours.com"
