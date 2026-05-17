# Integración tienda → demanda en JobsHours (mapa)

Las tiendas pueden **publicar una demanda** (publicación dorada) desde **su propio servidor**, por ejemplo cuando el pago del pedido queda aprobado. Así no dependen de que el cliente abra un link en el navegador.

Hay dos enfoques complementarios:

| Enfoque | Uso |
|----------|-----|
| **API servidor** (`POST /api/v1/integrations/store-demand`) | Webhook o job tras pago; datos mínimos en JSON. |
| **Deep link en navegador** (`?pubdemanda=1&…`) | Cliente abre JobsHours y confirma en el modal (ver `jobshour-web` / `integrateDemandFromUrl.ts`). |

Este documento describe la **API servidor**.

---

## 1. Requisitos

- Migraciones aplicadas (`store_demand_integrations`, `store_demand_partner_publishes`, `allowed_ips`).
- Un **usuario JobsHours** (`users.id`) que será el **cliente titular** de la demanda (suele ser una cuenta de la tienda).
- Opcional: **categoría por defecto** en la integración para no enviar `category_id` en cada POST.

---

## 2. Crear la integración y el token

El token **solo se muestra una vez**; se guarda hasheado (SHA-256) en base de datos.

```bash
php artisan store-demand:integration {user_id} "Nombre tienda" [--category=ID] [--ips=IP1,IP2]
```

Ejemplo:

```bash
php artisan store-demand:integration 42 "Donde Morales" --category=3 --ips=203.0.113.10
```

- **`--ips`**: lista blanca **opcional**; solo esas IPs (IPv4 o IPv6, exactas) podrán llamar al endpoint con un token válido. Si se omite, cualquier IP puede llamar si el Bearer es correcto.
- **`--category`**: si está definido, el POST puede omitir `category_id` salvo que quieran sobreescribirlo por pedido.

---

## 3. Rotar el token

Si el token se filtra o querés rotación periódica:

```bash
php artisan store-demand:integration-rotate {integration_id}
```

El token anterior deja de funcionar de inmediato.

---

## 4. Cambiar la lista blanca de IPs

```bash
php artisan store-demand:integration-ips {integration_id} "203.0.113.10,198.51.100.2"
php artisan store-demand:integration-ips {integration_id} --clear
```

Las IPs se validan al guardar (formato inválido → error del comando).

---

## 5. Endpoint

- **Método:** `POST`
- **Ruta:** `{APP_URL}/api/v1/integrations/store-demand`
- **Cabecera:** `Authorization: Bearer <token>`
- **Cuerpo:** `Content-Type: application/json`

### JSON mínimo

```json
{
  "external_order_id": "pedido-12345",
  "description": "Retiro en tienda X, entrega en dirección Y. Cliente: …",
  "lat": -36.6063,
  "lng": -72.1034
}
```

Si la integración **no** tiene `default_category_id`, agregar **`category_id`** (ID existente en `categories`).

### Campos útiles opcionales

| Campo | Notas |
|-------|--------|
| `category_id` | Sobreescribe el default de la integración. |
| `offered_price` | Número ≥ 0. |
| `ttl_minutes` | 5–120; por defecto 30. |
| `type` | `express_errand` (default), `fixed_job`, `ride_share`. |
| `store_name`, `pickup_address`, `delivery_address`, `pickup_lat`, `pickup_lng`, `delivery_lat`, `delivery_lng` | Mandado / delivery. |
| `idempotency_key` | Si se envía, sustituye a `external_order_id` como clave de idempotencia (reintentos de webhook). |

### Respuestas

- **201:** demanda creada. `data.request_id`, `data.pin_expires_at`, `data.idempotent: false`.
- **200:** misma clave de idempotencia que un pedido ya registrado. `data.idempotent: true`, mismo `request_id`.
- **401:** Bearer ausente o token inválido.
- **403:** IP no permitida (lista blanca activa).
- **422:** validación, categoría faltante, o geofence (punto fuera de zona piloto si está activa).

---

## 6. Idempotencia

- Por defecto la clave es **`external_order_id`** por integración.
- Si enviás **`idempotency_key`**, se usa esa cadena en su lugar (útil si `external_order_id` cambia entre reintentos pero es el mismo evento de pago).

Reintentos concurrentes con la misma clave no duplican demandas (una sola fila en `store_demand_partner_publishes`).

---

## 7. Límites y observabilidad

- **Rate limit:** `partner-store-demand` — **60 solicitudes/minuto** por token (o por IP si no hay Bearer). Distinto del modal autenticado (`demand`: 5/min por usuario).
- **Logs** (canal por defecto de Laravel, p. ej. `storage/logs/laravel.log`):
  - `store_demand_integration.publish` — éxito (incluye `integration_id`, `external_order_id`, `service_request_id`, `idempotent`, `client_ip`).
  - `store_demand_integration.ip_blocked` — token válido, IP no en lista.
  - `store_demand_integration.outside_zone` — geofence.

---

## 8. Ejemplo cURL

```bash
curl -sS -X POST "https://api.tudominio.com/api/v1/integrations/store-demand" \
  -H "Authorization: Bearer jdh_…" \
  -H "Content-Type: application/json" \
  -d "{\"external_order_id\":\"ord-999\",\"description\":\"Pedido pagado — delivery\",\"lat\":-37.6672,\"lng\":-72.5730}"
```

---

## 9. Seguridad (resumen)

- Guardar el token como **secreto** (variables de entorno del servidor de la tienda, vault, etc.), nunca en frontend público.
- Usar **HTTPS** siempre.
- Activar **`--ips`** con la(s) IP(s) salientes fijas del servidor que llama a la API (si las tienen).
- Rotar token ante incidente o por política interna.

---

## 10. Implementación interna (referencia dev)

- Controlador: `App\Http\Controllers\Api\V1\StorePartnerDemandController`.
- Lógica compartida con el modal: `App\Services\DemandPublishService`.
- Modelos: `StoreDemandIntegration`, `StoreDemandPartnerPublish`.
- Comandos: `CreateStoreDemandIntegration`, `RotateStoreDemandIntegration`, `SetStoreDemandIntegrationIps`.
- Soporte: `App\Support\IntegrationIpList` (validación de IPs en comandos).
- Rate limit: `partner-store-demand` en `App\Providers\AppServiceProvider`.
- Tests: `tests/Feature/StorePartnerDemandTest.php`, `tests/Feature/StoreDemandIntegrationCommandsTest.php`, `tests/Unit/IntegrationIpListTest.php`.
