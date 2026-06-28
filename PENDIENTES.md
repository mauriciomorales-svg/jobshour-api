# Pendientes — JobsHours + ecosistema

Lista de trabajo **pendiente u opcional** (post-auditoría y deploy 2026-06-03).  
Última revisión: **2026-06-07**.

---

## DondeMorales → demanda delivery (store-demand)

Documento estrategia: **`inventario-api/docs/PENDIENTE-MODULO-MINIMARKET-FAMILIAR-2026.md`**

DondeMorales.cl publica mandado tras pago con envío; repartidor Jobshours **acepta** en mapa (fase 1: Isabel; después red abierta). Productos pagados en tienda; envío en Jobshours.

| Estado | Tarea | Notas |
|--------|--------|--------|
| ⏸️ Pendiente | **Confirmar integración DM activa en prod** | `JOBSHOURS_STORE_DEMAND_ENABLED`, token, categoría. Endpoint: `POST /api/v1/integrations/store-demand`. |
| ⏸️ Pendiente | **Prueba E2E: pack DM → demanda → aceptar → completar** | Con cliente real o `--publish` desde `commerce:simulate-delivery-order`. |
| ⏸️ Pendiente | **Oferta repartidores Renaico** | Sin drivers en zona, demandas expiran (`ttl_minutes`); plan captación o Isabel fallback. |
| ⏸️ Dogfooding | **Descripción mandado clara** | «Pack Familiar DM #X — productos pagados — retiro Watt 205». |

Doc técnica: `docs/INTEGRACION-TIENDA-DEMANDA.md`.

---

## JobsHours — producto / transacciones

| Estado | Tarea | Notas |
|--------|--------|--------|
| ⏸️ Pendiente | **Cobro real de penalizaciones** | Hoy se calculan en BD; no hay cobro MP/Flow automático al incumplir SLA. |
| ⏸️ Pendiente | **Filtrar listados por solicitud derivada** | Evitar duplicados en feed/map: ocultar pin padre tomado o mostrar solo `derived_from_demand_id` en listados operativos. |
| ⏸️ Pendiente | **Commit y push a GitHub** | Cambios de chat, MP capture, take demanda y geolocalización están en local + VPS (SCP). Falta subir a `origin/master` en `jobshour-api` y `jobshour-web`. |
| ⏸️ Pendiente | **Verificación manual en prod** | Flujo: tomar demanda → chat (id derivado) → pago MP → reseña (solo si `payment_status=completed`). |
| ⏸️ Opcional | **`MP_CAPTURE_IMMEDIATELY` en `.env` prod** | Default `true` en código. Revisar si se deja explícito en VPS (`/var/www/jobshour-api/.env`). Hoy `MP_USE_SANDBOX=true`. |

### Ya desplegado (2026-06-03)

- API: `ServiceRequestChatAccess`, captura MP, gate de reseñas, Nominatim/geocoding.
- Web: `takePublicDemand()`, chat tras tomar demanda, `MisSolicitudes` pending con trabajador.
- VPS: build web `BUILD_ID=PNZoa44TDuCEo7yTcasjP`, `pm2 reload jobshour-web`, health API 200.

---

## Infra JobsHours

| Estado | Tarea | Notas |
|--------|--------|--------|
| ⏸️ Pendiente | **Espejo 1:1 VPS → local** | Código principal en `www`; faltan backups nginx/PM2, datos DB, snapshots `inventario-api-backup-*`. |
| ⏸️ Opcional | **Deploy vía git** | Sustituir SCP manual por `deploy/deploy-all.sh` tras push a remoto. |

---

## App Flutter — `gestion_inventario`

| Estado | Tarea | Notas |
|--------|--------|--------|
| ✅ Hecho (2026-06-03) | **Compilar versión actual (APK release)** | APK: `apps_flutter\gestion_inventario\build\app\outputs\flutter-apk\app-release.apk` (~70 MB). Versión **1.0.0+1**. Fix menor en `detalle_screen.dart` (tipos Dart 3.10). |

### Comandos para compilar (cuando se pida)

```powershell
cd c:\wamp64\www\apps_flutter\gestion_inventario
flutter pub get
flutter analyze
flutter build apk --release
```

APK de salida:

```text
build\app\outputs\flutter-apk\app-release.apk
```

Alternativa (App Bundle para Play Store):

```powershell
flutter build appbundle --release
```

Requisitos locales: Flutter SDK, Android SDK, Java 17 (según `build.gradle.kts`).

---

## Referencias

- Deploy API: `deploy/deploy.sh`, `deploy/deploy-all.sh`
- Deploy web: `../jobshour-web/scripts/deploy-on-server.sh`
- Estado ecosistema: `../ESTADO_SISTEMA.md`
- Auditoría transacciones/chat: conversación y cambios en `app/Support/ServiceRequestChatAccess.php`, `src/lib/takeDemand.ts`
