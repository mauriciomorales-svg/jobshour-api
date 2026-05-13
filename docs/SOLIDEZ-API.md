# Solidez — API (Laravel)

Resumen de endurecimientos aplicados en código y pendientes operativos.

## Hecho en código

- **`X-Request-Id`**: middleware `AssignRequestId` en el grupo `api`. El cliente puede enviar `X-Request-Id` para correlación; si no, se genera UUID. Se devuelve en la respuesta y se añade al contexto de logs (`request_id`). Al terminar el request se limpia el contexto compartido de logs.
- **Throttle webhooks MP**: rutas `POST /api/v1/payments/mp/webhook` y `POST /api/v1/store/webhook` con `throttle:mercadopago-webhook` (300 req/min por IP).
- **Idempotencia tienda**: `StoreMercadoPagoWebhookProcessor` registra eventos en `mp_webhook_events` (`event_type = store_order`), alineado con pagos de servicio / boost / créditos.
- **Tests**: `StoreMercadoPagoWebhookIdempotencyTest`, `AssignRequestIdMiddlewareTest`.

## Observabilidad (configuración tuya)

- **Sentry**: `sentry/sentry-laravel` + `bootstrap/app.php` ya reportan si `SENTRY_LARAVEL_DSN` está definido en `.env`.
- **Horizon / colas**: revisar `failed_jobs` y alertas si el worker cae.

## Auditoría de dependencias

Ejecutar periódicamente:

```bash
composer audit
composer update   # tras revisar changelog, especialmente league/commonmark y phpseclib si aparecen CVEs
```

Los avisos dependen de la versión bloqueada por Laravel y transitivos; no uses `composer update` a ciegas en producción sin probar en staging.

## Backups y recuperación

- Ver `jobshour-web/docs/MVP-DEPLOY-RUNBOOK.md` (backups, rollback).
- Probar restauración en un entorno de prueba al menos una vez por trimestre.
