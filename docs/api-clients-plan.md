# API Clients Plan — Acceso B2B con credenciales, tiers y revocación

> Fase para que consumidores externos (portales turísticos como turinorte, agencias, gobiernos) consuman la API con **credenciales propias**, límites por plan, medición de uso y revocación. Reemplaza el anti-patrón actual (token de super-admin embebido en el navegador de turinorte).
> Fecha: 2026-07-07 · Stack: Laravel 9 / PHP 8.0 / Sanctum. Ver también `docs/security-hardening-plan.md` y `docs/api-readiness.md`.

## Motivación (por qué NO endpoints públicos anónimos)

- **CORS no controla acceso** — es una regla del navegador. Cualquiera con `curl` ignora CORS. Un endpoint anónimo se consume para siempre, sin identidad → **no se puede medir, cobrar ni revocar**.
- Como el acceso es una **membresía paga que hay que poder cortar**, cada consumidor necesita una **credencial identificable** (API key), no anonimato.
- La key vive **server-side** en el consumidor (BFF). Nunca en el bundle del navegador.

## Modelo

- **`Client`** (ya existe: agencia/gobierno/empresa/portal) es el titular de la membresía de API.
- Cada `Client` puede tener **una o más API keys** (rotación sin downtime, una por entorno).
- Cada `Client` tiene un **tier** que define su rate limit (y a futuro cuotas/facturación).
- El `Client` ve **solo los accommodations relacionados** (pivote `accommodation_client`, ya existe) — reutiliza `Accommodation::scopeVisibleTo()` y la `AccommodationPolicy` del hardening.
- Acceso: **lectura de catálogo** + **creación de bookings** (un portal reserva en nombre del turista). Nada de escritura sobre el catálogo.

### Credencial: API keys propias (no Passport)

Se descarta Passport (dependencia pesada, OAuth innecesario para este caso). Se usan **API keys estilo Stripe**, con control total sobre tier/uso/revocación:

- Tabla `client_api_keys`: `id`, `client_id`, `name`, `prefix` (visible, ej. `tk_live_a1b2`), `key_hash` (sha256 del secreto completo), `last_used_at`, `expires_at` (nullable), `revoked_at` (nullable), `abilities` (json: `["catalog:read","booking:create"]`), timestamps.
- El secreto en claro se muestra **una sola vez** al generarla. Se guarda solo el hash.
- Formato: `tk_live_<prefix>_<secret>` → el server parte por prefix, busca, y compara hash.

## Fases

### F1 — Credencial y autenticación de client

1. Migración `client_api_keys` (arriba). Agregar a `Client`: `hasMany apiKeys()` y un campo de tier (`rate_limit_per_minute` nullable, o `plan_id` → catálogo `Plan` con feature `api_rate_limit`; **recomendado empezar con la columna simple**, graduar a Plan-feature después).
2. Middleware `AuthenticateApiClient` (alias `auth.client`):
   - Lee `Authorization: Bearer tk_live_…` (o header `X-Api-Key`).
   - Resuelve por `prefix`, valida `key_hash`, `revoked_at IS NULL`, no expirada.
   - Rechaza (401) si inválida/revocada/expirada.
   - Setea el client actuante y actualiza `last_used_at`.
   - **Reutiliza el modelo de tenencia:** setea un user resolver a un `User` en memoria `['user_type' => 'client', 'client_id' => $client->id]` → así `AccommodationPolicy` y `scopeVisibleTo()` funcionan sin cambios.
3. Registrar `auth.client` en `Kernel.php`.

### F2 — Rate limiting por client/tier

4. Limiter `client` en `RouteServiceProvider`: `Limit::perMinute($client->rateLimit())->by("client:{$client->id}")`, con respuesta 429 estandarizada y headers `X-RateLimit-*`.
5. `Client::rateLimit()`: devuelve `rate_limit_per_minute` o un default de config (`config('api.client_default_rpm')`). A futuro: leer de `plan->featureLimit('api_rate_limit')`.

### F3 — Superficie de la API de clients

6. Nuevo archivo `routes/client-api.php` bajo prefijo `/api/client/v1` (nombre ajustable), middleware `['auth.client', 'throttle:client']`. Endpoints (whitelist):
   - `GET accommodations` (scopeado al pivote), `GET accommodations/{slug}`, `GET accommodations/{id}/availability`
   - `GET cities`, `GET tours`, `GET services`, `GET accommodation-types`
   - `POST bookings` (crear pre-reserva; requiere ability `booking:create`)
7. **Resources públicos** (`app/Http/Resources/Public/…`): versión recortada que NO expone datos internos (`account_id`, emails/teléfonos de reservas, notas, tokens). Solo lo que un portal necesita mostrar.
8. Registrar el nuevo grupo en `RouteServiceProvider` (o reutilizar el prefijo `/pms` que quedó libre).

### F4 — Medición y gestión (platform admin)

9. Uso: tabla `client_api_usage` (`client_id`, `date`, `count`) con upsert/`increment` por request (barato) para facturar por volumen. MVP puede empezar solo con `last_used_at`.
10. Endpoints de gestión (solo `user_type=platform`, bajo `/api/admin/v1`):
    - `POST clients` / `GET clients` / `PATCH clients/{id}` (tier)
    - `POST clients/{id}/accommodations` / `DELETE …` (relacionar qué ve)
    - `POST clients/{id}/keys` (genera key, muestra secreto una vez) / `DELETE clients/{id}/keys/{keyId}` (revoca → `revoked_at`)

### F5 — Migrar turinorte a server-side

11. Mover los fetches de catálogo a **route handlers / server components** de Next.
12. Usar `API_KEY` (env **server-only**, NO `NEXT_PUBLIC_`). Apuntar a `/api/client/v1`.
13. **Eliminar `NEXT_PUBLIC_API_TOKEN`** y **revocar el token 35** (super-admin expuesto). turinorte está en dev → se puede revocar sin downtime.
14. Agregar el origen de turinorte a CORS solo si algo queda llamando desde el browser (con SSR, no hace falta).

### F6 — Tests

15. Feature tests: key válida → 200 scopeada al pivote; key revocada/expirada → 401; sobre-límite → 429; client A no ve accommodations de client B; escritura de catálogo bloqueada; `POST bookings` permitido con ability.

## Checklist

- [x] F1.1 Migración `client_api_keys` + tier en `Client` + relación `apiKeys()` (aplicada; + `config/api.php` con `client_default_rpm`)
- [x] F1.2 Middleware `AuthenticateApiClient` (`auth.client`) reusando tenencia
- [x] F1.3 Registrar `auth.client` en Kernel
- [x] F2.4 Limiter `client` por tier + headers RateLimit (en `RouteServiceProvider`; headers X-RateLimit-* automáticos)
- [x] F2.5 `Client::rateLimit()` con default de config (`api.client_default_rpm`)
- [x] F3.6 `routes/client-api.php` (whitelist read + booking; middlewares `json.response`+`auth.client`+`throttle:client`, `client.ability` en bookings)
- [x] F3.7 Resources públicos en `app/Http/Resources/Public/` (sin account_id/plan_id/enabled)
- [x] F3.8 Registrar grupo de rutas (`/api/client/v1` en `RouteServiceProvider`) + controladores `Api\Client\*`
- [x] F4.9 Medición de uso (`client_api_usage` con upsert atómico `ClientApiUsage::hit()` en el middleware + `last_used_at`)
- [x] F4.10 Endpoints de gestión (`/api/admin/v1/clients*`, middleware `platform`): CRUD clients + tier, attach/detach accommodations, generar/revocar keys
- [ ] F5.11-14 turinorte server-side + `API_KEY` + revocar token 35 + quitar `NEXT_PUBLIC_API_TOKEN`
- [ ] F6.15 Feature tests de la API de clients

## Decisiones (resueltas 2026-07-07)

1. **Prefijo de rutas**: ✅ `/api/client/v1`.
2. **Tier**: ✅ columna `rate_limit_per_minute` en `Client` (graduar a Plan-feature después).
3. **Header de credencial**: ✅ **solo** `Authorization: Bearer` (más simple; sin `X-Api-Key`).
