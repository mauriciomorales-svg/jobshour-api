# Flow.cl — en standby (no eliminado)

Mercado Pago es la **pasarela activa** en web y API. Flow queda **implementado pero apagado** por configuración, listo para reactivar sin redeploy de código.

## Estado actual

| Pieza | Estado |
|-------|--------|
| `POST /api/v1/payments/flow/init` | **503** si `PAYMENT_GATEWAY≠flow` |
| `GET/POST /api/v1/payments/flow/confirm` | Activo (pagos antiguos con `?token=`) |
| `GET/POST /api/v1/payments/flow/return` | Activo (retorno Flow) |
| Web checkout Flow | No llamado; solo MP (`paymentGateway.ts`) |
| `/pago/resultado?token=` | Confirma pagos Flow legacy |

## Variables (opcionales mientras esté en standby)

```env
PAYMENT_GATEWAY=mercadopago   # default — no cambiar para producción actual

# Solo si reactivás Flow:
# PAYMENT_GATEWAY=flow
# FLOW_API_KEY=
# FLOW_SECRET_KEY=
# FLOW_SANDBOX=true
# FRONTEND_URL=https://jobshours.com
```

## Reactivar Flow (checklist)

1. Cuenta y claves en [flow.cl](https://www.flow.cl).
2. Completar `FLOW_*` en `.env` del API.
3. `PAYMENT_GATEWAY=flow` y `php artisan config:clear`.
4. En **web**: volver a conectar `POST /payments/flow/init` en el cliente (hoy solo MP).
5. Probar sandbox antes de producción.

## No borrar

- `App\Http\Controllers\Api\V1\FlowController`
- Rutas en `routes/api.php` (públicas confirm/return + init autenticado)
- Migración `add_flow_support_to_payments`
