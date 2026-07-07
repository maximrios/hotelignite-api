# Security Hardening Plan — Salir a internet (Fase crítica)

> Plan de endurecimiento mínimo para exponer la HotelIgnite API públicamente sin filtrar/permitir edición cruzada de datos entre cuentas.
> Fecha: 2026-07-07 · Stack: Laravel 9 / PHP 8.0 / Sanctum.
> Alcance elegido: **solo hardening crítico**. Fuera de alcance en esta fase: upgrade Laravel/PHP, credenciales M2M completas de clients, rate-limit por plan, OpenAPI completo. Ver `docs/api-readiness.md` para el roadmap total.

## Contexto y veredicto

- El **PMS ya autentica bien**: login (`POST /api/auth/login`) → token Sanctum → cookie `httpOnly`/`secure` → Bearer reenviado server-side desde los route handlers de Next.js. El problema **no** es falta de login.
- El problema real es **autorización**: todo token es superusuario global. No hay scopes, ni Policies, ni tenencia. Cualquier token válido puede `POST/PUT/DELETE` sobre los recursos de **cualquier** cuenta pegándole directo a Laravel (saltando el front).
- El filtrado en el front **no es seguridad** — la API es pública e independiente del PMS.

## Modelo de acceso (decisión)

- **Account** = administra uno o más `accommodations` (CRUD). Varios users por account.
- **Client** = entidad externa (agencia/gobierno/empresa) que **lee** accommodations relacionados. Varios users por client. *(Se cablea en una fase posterior; en esta fase solo se prepara el schema.)*
- **Platform** = super-admin (nosotros), bypassa la tenencia.
- Un user pertenece a **un** account **o** **un** client (o es platform). En `users`: `user_type` + `account_id`/`client_id` nullable.
- La relación client↔accommodations es **M2M** (pivote `accommodation_client`).

Enforcement con **scoping explícito** en controladores/repos + Policies, **no** Global Scopes de Eloquent (footgun en seeders/jobs).

---

## A. Identidad y tenencia

1. Migración `users`: `user_type` (`account`|`client`|`platform`, default `account`), `account_id` (nullable FK→accounts), `client_id` (nullable FK→clients).
2. Migración `clients` + pivote `accommodation_client` (schema listo, sin endpoints todavía).
3. Modelo `User`: relaciones `account()`/`client()`; helpers `isPlatform()`/`isAccount()`/`isClient()`; `$fillable`/`$casts`.
4. Backfill: comando/seeder para asignar users existentes a un `account_id` o marcarlos `platform`. **Obligatorio antes de deploy** (si no, quedan sin cuenta y no ven nada).

## B. Autorización (backend enforce)

5. `AccommodationPolicy` (`viewAny`/`view`/`create`/`update`/`delete`): account solo si `accommodation.account_id === user.account_id`; platform bypassa; client read-only. Registrar en `AuthServiceProvider`.
6. Controladores Accommodation (admin/V1/pms): `index` scopeado por `account_id` del user (quitar el filtro `account_id` controlado por cliente; solo platform lo pasa libre); `store` fuerza `account_id`; `show/update/destroy` con `authorize()`.
7. Middleware liviano que niega escritura (`POST/PUT/PATCH/DELETE`) a `user_type=client`.
8. Extender tenencia a recursos hijos (rooms, room-types, rates, availability, etc.) — al menos negar escritura cruzada.

## C. Endurecer credenciales / superficie

9. Rate limit de login: limiter `login` (ej. 5/min por email+IP) en `POST /auth/login`. `GET v1/channels` bajo throttle.
10. Expiración de tokens (`config/sanctum.php` `expiration` por env); revocar token al cambiar password.
11. Tokens con abilities según `user_type` (defensa en profundidad; enforcement real vía Policy).
12. CORS: `paths => ['api/*']`, orígenes desde `CORS_ALLOWED_ORIGINS` (env), sin `localhost` hardcodeado en prod.

## D. Cerrar canal roto

13. `routes/pms.php` `middleware(['client'])` no existe (Passport no instalado) → esas rutas `bookings` tiran 500 y el PMS no las usa. Comentar/quitar ahora; se reconstruye en la fase de clients.

## E. Verificación

14. Feature tests del límite de tenencia: user A no lee/edita accommodations de B (403); client no escribe; platform puede todo.

---

**Orden:** A → B → E (habilita salir seguro) → C → D.

## Checklist

- [x] A1 Migración `users` (user_type, account_id, client_id) — sin FK a nivel DB (accounts legacy `int`/latin1)
- [x] A2 Migración `clients` + `accommodation_client`
- [x] A3 Modelo `User` (relaciones + helpers) + `Client` + relaciones inversas
- [x] A4 Backfill de users existentes (comando `users:backfill-tenancy`; user actual → platform)
- [x] B5 `AccommodationPolicy` + registro en `AuthServiceProvider`
- [x] B6 Scoping en controladores Accommodation (Admin/V1) + scope `Accommodation::visibleTo()`
- [x] B7 Middleware `client.readonly` (`EnsureNotReadOnlyClient`) en grupos auth de api.php/pms.php
- [ ] B8 Tenencia en recursos hijos (rooms, room-types, rates, availability, etc.) — PENDIENTE. Patrón: scopear por `accommodation.account_id` del padre; para writes, `authorize` contra el accommodation dueño. Superficie grande; candidato a sub-fase propia.
- [x] C9 Throttle en login (`throttle:login`, 5/min por email+IP) + `channels` bajo `throttle:api`
- [x] C10 Expiración de tokens (`SANCTUM_TOKEN_EXPIRATION`, default 1440 min) + revocación al cambiar password
- [x] C11 Abilities por user_type en login (client → `['read']`, resto → `['*']`)
- [x] C12 CORS por env (`CORS_ALLOWED_ORIGINS`), paths `api/*`+`pms/*` (sin `*` ni localhost hardcodeado)
- [x] D13 `routes/pms.php` deshabilitado (rutas rotas/sin uso: middleware `client` inexistente + controller ausente)
- [x] E14 Feature tests de tenencia (`tests/Feature/AccommodationTenancyTest.php`, 10 tests, usa `DatabaseTransactions`)
