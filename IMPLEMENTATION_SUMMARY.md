# JobsHours - Implementación Módulos PRD

**Fecha:** 16 Feb 2026  
**Sprint:** Escalamiento & Publiguía Dinámica

---

## ✅ MÓDULO 1: BÚSQUEDA INTELIGENTE (Weighted & Fuzzy)

### Backend
- **Archivo:** `app/Http/Controllers/Api/V1/SearchController.php`
- **Ruta:** `GET /api/v1/search?q={query}&lat={lat}&lng={lng}&radius={radius}`
- **Características:**
  - Tokenización de query con split por espacios
  - Scoring ponderado: Título (100), Categoría (80), Skills (60), Bio (50), Fuzzy (20)
  - Mapa de sinónimos chilenos: gafiter→gasfíter, elecricista→electricista, etc.
  - Levenshtein distance para corrección de typos (threshold 30-35%)
  - Strip accents para matching flexible (á→a, ñ→n)
  - Búsqueda en categorías con fuzzy matching
  - Retorna top 30 resultados ordenados por score

### Frontend
- **Archivo:** `src/app/components/FullScreenSearchOverlay.tsx`
- **Características:**
  - Modal fullscreen con auto-focus en input
  - Debounce 300ms en digitación
  - Speech-to-Text integrado (botón micrófono)
  - Highlight de matches en resultados (negrita azul)
  - Lazy loading de avatares
  - Sugerencias rápidas (Gasfíter, Electricista, etc.)
  - Muestra score y matches de debug
  - Click en resultado → abre detalle del worker

---

## ✅ MÓDULO 2A: ENMASCARAMIENTO DE TELÉFONO + CRÉDITOS

### Migraciones
- `2026_02_16_170000_add_credits_and_pioneer_to_users.php`
  - `credits_balance` (int, default 0)
  - `is_pioneer` (bool, default false)
- `2026_02_16_170001_create_contact_reveals_table.php`
  - Tabla de log de revelaciones (user_id, worker_id, was_free)

### Backend
- **Modelo:** `app/Models/ContactReveal.php`
- **Controller:** `app/Http/Controllers/Api/V1/ContactRevealController.php`
- **Rutas:**
  - `POST /api/v1/contact/reveal` - Revela teléfono (cuesta 1 crédito, gratis para pioneros)
  - `GET /api/v1/contact/check/{workerId}` - Verifica si ya reveló
- **Lógica:**
  - Pioneros: revelan ilimitadamente gratis
  - Usuarios regulares: 1 crédito por revelación
  - Sin créditos: HTTP 402 Payment Required
  - Idempotente: no cobra 2 veces por el mismo worker
- **Enmascaramiento:** `ExpertController@show` siempre retorna teléfono enmascarado (ej: `+5******14`)

### Seeder
- `database/seeders/PioneerUsersSeeder.php`
  - Todos los usuarios existentes → pioneros
  - Usuario empresa test: `empresa@test.com` / `password`
  - Usuario regular test: `regular@test.com` / `password` (5 créditos)

---

## ✅ MÓDULO 2B: PRIVACIDAD VIEWER

### Backend
- **Archivo:** `app/Http/Controllers/Api/V1/ExpertController.php` (método `show`)
- **Características:**
  - Debounce 24h: solo 1 vista por viewer-worker pair cada 24h
  - Identifica viewer por `user_id` (autenticado) o `viewer_ip` (guest)
  - Broadcast `ProfileViewed` activado (antes estaba comentado)
  - Geo-fuzzing ya implementado en `nearby` (offset random ±10m)
  - Notificación push vía Echo/Pusher con ciudad aproximada

---

## ✅ MÓDULO 3: PERFILAMIENTO EMPRESARIAL

### Migración
- `2026_02_16_170002_add_company_fields_to_users.php`
  - `is_company` (bool)
  - `company_rut` (string, 20)
  - `company_razon_social` (string, 200)
  - `company_giro` (string, 200)

### Modelo
- **User.php** actualizado con campos en `$fillable` y `$casts`
- Listo para validación RUT Módulo 11 (pendiente implementar en frontend)

---

## 📊 ESTADO DE IMPLEMENTACIÓN

| Módulo | Backend | Frontend | Estado |
|--------|---------|----------|--------|
| 1A: Búsqueda Weighted/Fuzzy | ✅ | ✅ | **COMPLETO** |
| 1B: Search Overlay + Mic | ✅ | ✅ | **COMPLETO** |
| 2A: Phone Masking + Credits | ✅ | ⚠️ | Backend listo, falta UI reveal |
| 2B: Viewer Privacy | ✅ | N/A | **COMPLETO** |
| 3: Company Profile | ✅ | ⚠️ | Campos listos, falta form registro |
| 4: WebP Compression | ❌ | ❌ | **PENDIENTE** |

---

## 🧪 TESTING

### Búsqueda
```bash
# Test fuzzy matching
curl "http://localhost:8000/api/v1/search?q=gafiter&lat=-37.67&lng=-72.57"
curl "http://localhost:8000/api/v1/search?q=elecricista&lat=-37.67&lng=-72.57"

# Test tokenization
curl "http://localhost:8000/api/v1/search?q=mecanico%20toyota&lat=-37.67&lng=-72.57"
```

### Phone Reveal
```bash
# Login as pioneer
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"empresa@test.com","password":"password"}'

# Reveal phone (free for pioneers)
curl -X POST http://localhost:8000/api/v1/contact/reveal \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"worker_id":1}'

# Check if revealed
curl http://localhost:8000/api/v1/contact/check/1 \
  -H "Authorization: Bearer {token}"
```

### Regular User (with credits)
```bash
# Login as regular
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"regular@test.com","password":"password"}'

# Reveal (costs 1 credit, has 5)
curl -X POST http://localhost:8000/api/v1/contact/reveal \
  -H "Authorization: Bearer {token}" \
  -d '{"worker_id":1}'
```

---

## 📝 PRÓXIMOS PASOS

### Alta Prioridad
1. **Frontend Phone Reveal UI**
   - Botón "Ver Teléfono" en worker detail
   - Modal confirmación con precio/créditos
   - Integrar con `ContactRevealController`

2. **Frontend Company Registration**
   - Toggle "¿Eres Empresa?" en registro
   - Campos condicionales: RUT, Razón Social, Giro
   - Validación RUT Módulo 11

### Media Prioridad
3. **WebP Compression**
   - Resize + compress en cliente antes de upload
   - Lazy loading de avatares en resultados
   - Progressive image loading

4. **Optimistic UI**
   - Service Worker Background Sync
   - Offline queue para solicitudes
   - Retry automático

5. **Re-contratar con 1 click**
   - Botón en historial de trabajos
   - Copiar datos de request anterior

6. **Chat Multimedia**
   - Upload de fotos en chat
   - Grabación de audio
   - Backend ya acepta `type: image|audio`

---

## 🔐 SEGURIDAD

- ✅ Teléfonos enmascarados en API pública
- ✅ Viewer ID nunca expuesto al worker
- ✅ Debounce 24h previene spam de notificaciones
- ✅ Geo-fuzzing protege ubicación exacta
- ✅ Sistema de créditos listo para monetización
- ⚠️ Falta: Rate limiting en `/search` endpoint
- ⚠️ Falta: CAPTCHA en registro empresa

---

## 📦 ARCHIVOS CREADOS

### Backend
- `app/Http/Controllers/Api/V1/SearchController.php`
- `app/Http/Controllers/Api/V1/ContactRevealController.php`
- `app/Models/ContactReveal.php`
- `database/migrations/2026_02_16_170000_add_credits_and_pioneer_to_users.php`
- `database/migrations/2026_02_16_170001_create_contact_reveals_table.php`
- `database/migrations/2026_02_16_170002_add_company_fields_to_users.php`
- `database/seeders/PioneerUsersSeeder.php`

### Frontend
- `src/app/components/FullScreenSearchOverlay.tsx`

### Modificados
- `routes/api.php` (2 nuevas rutas)
- `app/Models/User.php` (campos company + credits)
- `app/Http/Controllers/Api/V1/ExpertController.php` (phone masking + debounce)
- `src/app/page.tsx` (integración search overlay)
