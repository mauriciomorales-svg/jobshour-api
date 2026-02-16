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
