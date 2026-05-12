# Jobshour API

Backend API for the Jobshour geospatial job marketplace.

## Tech Stack

- **Framework:** Laravel 11
- **Database:** PostgreSQL 16 + PostGIS (geospatial)
- **Cache/Queue:** Redis
- **WebSockets:** Laravel Reverb
- **Auth:** Laravel Sanctum
- **Containerization:** Docker Compose

## Services (Docker)

| Service | Port | Description |
|---------|------|-------------|
| jobshour-api | 9000 (internal) | PHP-FPM Laravel app |
| jobshour-nginx | 8000 | Nginx reverse proxy |
| jobshour-db | 5433 | PostgreSQL + PostGIS |
| jobshour-redis | 6380 | Redis cache/queue |
| jobshour-reverb | 8080 | WebSocket server |

## Quick Start

```bash
# 1. Copy env
cp .env.example .env

# 2. Start containers
docker-compose up -d

# 3. Install dependencies
docker exec jobshour-api composer install

# 4. Generate key
docker exec jobshour-api php artisan key:generate

# 5. Run migrations
docker exec jobshour-api php artisan migrate

# 6. Start Reverb WebSocket server
docker exec -d jobshour-api php artisan reverb:start
```

## API Endpoints

### Product analytics (retención / embudo web)
- `POST /api/v1/analytics/events` — Body JSON: `{ "name": string, "payload": object, "t": number }` (timestamp cliente en ms). Con cabecera opcional `Authorization: Bearer <Sanctum>` se guarda `user_id` para cohortes.
- Si `ANALYTICS_INGEST_SECRET` está definido en `.env`, enviar cabecera `X-Analytics-Secret` con el mismo valor.
- Desde Next.js: reenvío opcional con `ANALYTICS_FORWARD_URL` + `ANALYTICS_FORWARD_SECRET` (ver `jobshour-web/docs/ENV.md`); la ruta `/api/jh-analytics` reenvía también `Authorization` si el navegador la envía.
- Migración: tabla `product_analytics_events` (columna `user_id` nullable).
- `php artisan analytics:prune` — borra eventos más antiguos que `ANALYTICS_RETENTION_DAYS` (por defecto 365); programado diario ~03:15.
- **Retención push (mismo copy que cintillos web):** `php artisan retention:push-open-requests` y `php artisan retention:push-worker-availability` (cooldown `RETENTION_PUSH_COOLDOWN_HOURS`); programados semanalmente (lunes / miércoles). Requiere FCM configurado. En producción debe correr el scheduler de Laravel (`* * * * * php artisan schedule:run`).
- **Admin:** variable `ADMIN_USER_IDS` (coma-separada; por defecto `21`).
- **Admin (Sanctum + usuario admin):**
  - `GET /api/v1/admin/analytics/summary` — agregados D1/D7, IPs, **usuarios distintos con evento** (`user_id`), **cohorte** semana a semana, y desglose por `name`.
  - `GET /api/v1/admin/analytics/events` — listado paginado.
  - `POST /api/v1/admin/demands/{id}/boost` — body `{ "hours": 24 }` fija `boosted_until` (demandas **pending** en mapa: orden con boosted primero en `GET /demand/nearby`).
- **Boost con pago (cliente, demanda pending):** `POST /api/v1/payments/mp/demand-boost` (auth) body `{ "service_request_id": id, "hours"?: 1-336 }` → Checkout Pro Mercado Pago; al aprobar, el webhook aplica `boosted_until`. Precio **`BOOST_DEMAND_PRICE_CLP`**, horas por defecto **`BOOST_DEMAND_HOURS`**. `FRONTEND_URL` para `back_urls`.
- **Privacidad / informes:** `php artisan analytics:anonymize-pii` (programado domingo ~04:00). `php artisan analytics:report-cohort-slack` si **`ANALYTICS_SLACK_WEBHOOK_URL`** (programado lunes ~09:00).

### Checklist despliegue (rápido)
1. `php artisan migrate --force` (o `composer migrate` · `.\scripts\migrate.ps1` en Windows) · 2. `.env` producción (`MP_ACCESS_TOKEN`, **`FRONTEND_URL`** URL del Next, **`MAIL_*`** SMTP u otro mailer, opcional **`SUPPORT_EMAIL`**) · 3. Cron cada minuto: `php artisan schedule:run` (ver `scripts/crontab-scheduler.example`) · 4. Probar pago MP en sandbox antes de producción · 5. Probar correo: con un pedido de tienda en `paid`, el comprador y el vendedor deberían recibir mail (si `MAIL_MAILER` no es solo `log`).

**Correo pedido tienda pagado:** `App\Services\StoreOrderPaidMailer` se ejecuta desde el webhook Mercado Pago y desde `POST /api/v1/store/orders/{id}/qa-paid` (solo no-prod). Requiere `FRONTEND_URL` apuntando al dominio donde vive `/tienda/success`.

**Scheduler (Laravel 11):** las tareas están en `bootstrap/app.php` (`->withSchedule()`). Comprobar con `composer schedule:list` o `php artisan schedule:list`.

**Tests:** `phpunit.xml` ya no fuerza puerto/host de PostgreSQL; PHPUnit usa la misma conexión que tu `.env`. En CI, define `DB_*` en el workflow.

### Auth
- `POST /api/auth/register` - Register
- `POST /api/auth/login` - Login
- `GET /api/auth/me` - Current user (auth)
- `POST /api/auth/logout` - Logout (auth)

### Workers
- `GET /api/workers` - List workers
- `POST /api/workers` - Create worker profile
- `GET /api/workers/{id}` - Get worker
- `PUT /api/workers/{id}` - Update worker
- `POST /api/workers/{id}/availability` - Update availability status
- `POST /api/workers/{id}/location` - Update GPS location
- `GET /api/workers/{id}/videos` - Worker videos

### Jobs
- `GET /api/jobs` - List jobs
- `POST /api/jobs` - Create job
- `GET /api/jobs/{id}` - Get job
- `PUT /api/jobs/{id}` - Update job
- `POST /api/jobs/{id}/apply` - Apply to job
- `POST /api/jobs/{id}/cancel` - Cancel job

### Map (PostGIS)
- `GET /api/map/nearby-workers?lat=&lng=&radius=` - Nearby workers
- `GET /api/map/clusters?lat=&lng=&zoom=` - Map clusters

### Videos
- `POST /api/videos` - Upload video
- `GET /api/videos/mine` - My videos
- `GET /api/videos/{id}` - Get video
- `DELETE /api/videos/{id}` - Delete video

### Payments
- `POST /api/payments/intent` - Create payment intent
- `POST /api/payments/{id}/confirm` - Confirm payment
- `GET /api/payments/wallet` - Wallet balance
- `GET /api/payments/history` - Payment history

## WebSocket Channels

- `map` - Worker availability and location updates
- `workers.{id}` - Worker-specific events
- `jobs.{id}` - Job-specific events

## Database Schema

- **users** - Auth with user types (worker/employer/admin)
- **workers** - Profile with PostGIS point location
- **jobs** - Postings with PostGIS location
- **videos** - Worker portfolio videos
- **work_sessions** - Active work tracking
- **payments** - Payment records
