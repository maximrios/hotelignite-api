# API Readiness — Exponer la API a clientes externos y portales turísticos

> Diagnóstico y plan de implementación para habilitar acceso de terceros (portales turísticos, OTAs, integradores) a la HotelIgnite API.
> Fecha de diagnóstico: 2026-06-15 · Stack actual: Laravel 9 / PHP 8.0 / Sanctum.

## Veredicto

**No está lista todavía.** La base de dominio es sólida (modelo de datos rico, patrón Repository, versionado `v1`, entitlements por plan, OpenAPI parcial), pero faltan las piezas centrales de una API pública/B2B: onboarding de clientes externos, autorización por scopes, aislamiento entre cuentas (multi-tenancy) y una pila con soporte de seguridad vigente.

---

## 🔴 Bloqueantes (impiden abrir la API hoy)

### 1. No existe mecanismo de credenciales para clientes externos
- Único login: `POST /api/auth/login` con **email + password** (`UserAuthController`) → token Sanctum personal.
- Sirve para el frontend propio, no para portales. No hay API keys, ni registro de aplicaciones, ni client_id/secret.
- **Acción:** implementar credenciales máquina-a-máquina (Passport `client_credentials` **o** tabla `api_clients` + middleware propio).

### 2. El middleware `client` está roto / Passport no instalado
- `routes/pms.php:21` usa `middleware(['client'])` pero:
  - `client` **no está registrado** en `app/Http/Kernel.php` (`$routeMiddleware`).
  - **Passport no está en `composer.json`** (solo Sanctum).
- Resultado: ese endpoint tira error al invocarse. El único canal pensado para integración M2M no funciona.
- **Acción:** decidir Passport vs. API keys propias y registrar/arreglar el middleware.

### 3. Cero autorización / scopes — todo token es superusuario
- Tokens creados con `createToken('api_token')` **sin abilities/scopes**.
- No hay Policies ni Gates. Cualquier usuario autenticado puede `POST/PUT/DELETE` sobre **cualquier** recurso.
- **Acción:** introducir scopes/abilities en los tokens y aplicarlos por ruta; agregar Policies.

### 4. No hay multi-tenancy / aislamiento de datos
- Los repositorios (`AccommodationRespository`, etc.) **no filtran por `account_id`/owner**. Filtran por ciudad, tipo, búsqueda — nunca por propietario.
- Cualquier cliente vería y editaría datos de **todos** los hoteles.
- **Acción:** scope global por `account_id` en modelos/repositorios.

---

## 🟠 Riesgos altos (resolver antes de producción pública)

### 5. Pila fuera de soporte de seguridad
- **Laravel 9** (EOL feb-2024) y **PHP 8.0.2** (EOL nov-2023). Sin parches de seguridad.
- **Acción:** subir a Laravel 10/11 + PHP 8.2+.

### 6. Tokens que nunca expiran
- `config/sanctum.php` → `'expiration' => null`. Sin expiración ni endpoint de revocación/rotación expuesto.
- **Acción:** setear expiración y exponer flujo de revocación/rotación.

### 7. Login sin rate limit + endpoint público sin throttle
- `POST /auth/login` (`api.php:52`) y `GET v1/channels` (`api.php:58`) están **fuera** del grupo `throttle:api` → fuerza bruta / abuso sin límite.
- **Acción:** aplicar `throttle` a login y a rutas públicas.

### 8. Rate limiting demasiado básico
- Bucket único global de **60 req/min** por user-id o IP (`RouteServiceProvider:52`). Sin tiers por cliente/plan ni límites por endpoint.
- **Acción:** rate limiting por cliente/plan (aprovechar los entitlements).

### 9. CORS demasiado abierto y hardcodeado
- `config/cors.php`: `paths => ['*']`, `supports_credentials => true`, `allowed_origins` con dominios + `localhost` **hardcodeados** (no por env).
- **Acción:** orígenes por env, `paths` limitado a `api/*`.

---

## 🟡 Madurez / operación

### 10. Sin tests de la API
- Solo quedan `ExampleTest` y tests de auth de Breeze. Sin regresión del dominio.
- **Acción:** suite de feature tests por endpoint.

### 11. Todo el trabajo está sin commitear
- `git status` muestra toda la feature actual como modificada/untracked. Nada en el historial.
- **Acción:** commitear y versionar antes de cualquier release.

### 12. Documentación OpenAPI parcial
- Hay `docs/openapi.yaml` + specs por recurso (buen punto de partida), pero incompleto y sin portal de developers / changelog / guía de errores.
- **Acción:** completar OpenAPI + portal de developers.

### 13. Rutas inconsistentes
- Mezcla de `admin/v1`, `v1`, `v1/web`, métodos no-REST (`PUT/GET booking` sin id), repos con typo histórico.
- **Acción:** normalizar el contrato público antes de publicarlo.

---

## ✅ Lo que ya está bien (base para construir)
- Versionado `/api/v1/` desde el día uno.
- Patrón Repository + Resources → contrato de salida limpio.
- Modelo de **entitlements por plan** (`hasFeature`/`featureLimit`) ya existe — ideal para gobernar acceso por API, **pero hoy no se aplica como middleware**.
- OpenAPI ya iniciado.

---

## Roadmap de implementación

### Fase 1 — Seguridad base (bloqueante)
1. Credenciales M2M reales: Passport (`client_credentials`) **o** API keys con tabla `api_clients` + middleware propio. Arreglar/registrar middleware `client`.
2. **Scopes/abilities** en tokens, aplicados por ruta.
3. **Multi-tenancy**: scope global por `account_id` en repositorios/modelos.
4. `throttle` en login y `channels`; expiración de tokens.

### Fase 2 — Endurecer
5. Laravel 10/11 + PHP 8.2+.
6. CORS por env, paths limitados a `api/*`.
7. Rate limiting por cliente/plan (entitlements).

### Fase 3 — Madurez de producto API
8. Suite de tests de feature por endpoint.
9. Completar OpenAPI + portal de developers, errores estandarizados, paginación/filtros documentados, idempotencia en `POST booking`.
10. Commitear y versionar todo el trabajo actual.

---

## Checklist rápido

- [ ] Credenciales M2M (Passport o API keys) + middleware `client` funcional
- [ ] Scopes/abilities por token
- [ ] Multi-tenancy por `account_id`
- [ ] Throttle en login y rutas públicas
- [ ] Expiración + revocación de tokens
- [ ] Upgrade Laravel 10/11 + PHP 8.2+
- [ ] CORS por env, paths `api/*`
- [ ] Rate limiting por plan
- [ ] Feature tests por endpoint
- [ ] OpenAPI completo + portal de developers
- [ ] Commit/versionado del trabajo actual
