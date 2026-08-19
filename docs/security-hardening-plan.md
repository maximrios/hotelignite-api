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
- [~] B8 Tenencia en recursos hijos — **anillo 1 parcial HECHO** (2026-07-12). Ver detalle abajo.
- [x] C9 Throttle en login (`throttle:login`, 5/min por email+IP) + `channels` bajo `throttle:api`
- [x] C10 Expiración de tokens (`SANCTUM_TOKEN_EXPIRATION`, default 1440 min) + revocación al cambiar password
- [x] C11 Abilities por user_type en login (client → `['read']`, resto → `['*']`)
- [x] C12 CORS por env (`CORS_ALLOWED_ORIGINS`), paths `api/*`+`pms/*` (sin `*` ni localhost hardcodeado)
- [x] D13 `routes/pms.php` deshabilitado (rutas rotas/sin uso: middleware `client` inexistente + controller ausente)
- [x] E14 Feature tests de tenencia (`tests/Feature/AccommodationTenancyTest.php`, 10 tests, usa `DatabaseTransactions`)

---

## B8 — Tenencia en recursos hijos

Mapa de la superficie, en anillos según cómo cada modelo llega al alojamiento dueño:

- **Anillo 1** (columna `accommodation_id` propia): Room, RoomType, AccommodationDescription, AccommodationService, AccommodationPolicy, AccommodationPolicyOld, AccommodationRatePolicy, Reservation, Booking, Inquiry.
- **Anillo 2** (vía `room_type_id`): RoomTypeBed, RoomTypeDescription, RoomTypeService, RatePlan, RoomAvailability.
- **Anillo 3**: Rate (`rate_plan_id` → RatePlan → RoomType) y AccommodationPolicyTranslation (`policy_id` → AccommodationPolicy).
- **Catálogos globales** (Service, Policy, Channel, City, Tour, TravelAgency, AccommodationType, AccountType, Plan): no son de nadie. Hoy cualquier usuario autenticado puede crear/editar/borrar catálogo de toda la plataforma — la escritura debe quedar bajo `platform`.
- **Account**: `GET /v1/accounts` lista todas las cuentas; escritura abierta. Lectura → scopear a la propia; escritura → `platform`.

### Patrón

1. Trait `App\Models\Concerns\BelongsToAccommodation`: cada modelo declara sólo su padre inmediato (`accommodationParent()`); la cadena se resuelve por recursión. Aporta `scopeVisibleTo($user)` — apoyado en `Accommodation::visibleTo()`, para que la definición de "qué veo" viva en un solo lugar — y `owningAccommodation()`.
2. `App\Policies\ChildOfAccommodationPolicy`: única policy para todos los hijos; resuelve el alojamiento dueño y delega en `AccommodationPolicy` (leer el hijo = ver el padre; escribirlo = editar el padre). De ahí sale gratis que los clients sean read-only.
3. Trait `App\Repositories\Concerns\ScopesToAccommodation`: `tenant()`, `writableAccommodation()` (exige poder editar el padre) y `visibleAccommodation()` (sólo verlo — es lo que corresponde cuando un portal B2B crea una pre-reserva).
4. En cada repo: listados y `find()` scopeados (404 en vez de 403 en cross-tenant, para no delatar la existencia del recurso); en `store`, el padre se resuelve contra `visibleTo()` y nunca se confía en el `accommodation_id` del payload; en `update`, se descarta `accommodation_id` (mudar un recurso a otro alojamiento es cambiarle el dueño, no editarlo).
5. `$request->all()` → `$request->validated()` en los repos: con `all()` cualquier campo fillable era mass-assignable, incluido el FK del dueño.

### Estado

- [x] Anillo 1: **Room, RoomType, Reservation, Booking** (repos scopeados, policy registrada, `validated()`).
- [ ] Anillo 1, resto: AccommodationDescription, AccommodationService, AccommodationPolicy, AccommodationPolicyOld, AccommodationRatePolicy, Inquiry.
- [ ] Anillo 2 y 3.
- [ ] Catálogos globales + Account bajo `platform`.
- [x] Tests: `tests/Feature/ChildResourceTenancyTest.php` (13 verdes, 1 skipped).

### Hallazgos abiertos (fuera del alcance de B8)

1. **Divergencia de esquema en `rooms` y `room_types`** — la DB (restaurada de un backup viejo) usa `created`/`modified` en vez de timestamps de Eloquent, y no tiene varias columnas que los modelos declaran fillable (`size`, `max_occupancy`, `slug`, `status`, `floor`, `notes`). Cualquier `Room::create()` / `update()` explota con `Unknown column 'updated_at'`: **`POST`/`PUT` de rooms y room-types están rotos hoy**, con o sin tenencia. Bloquea el test `account_user_no_puede_mudar_su_room_a_otro_alojamiento` (skipped).
2. **`POST /admin/v1/accommodations` sin `city_id` devuelve 500**, no 422: la columna es `NOT NULL` sin default y la Form Request no la exige.
3. **¿Un client B2B debería ver las reservas del hotel?** Con la policy actual, un usuario `user_type=client` con token Sanctum podría leer las reservas (con datos de los huéspedes) de todos los alojamientos relacionados. Hoy no es alcanzable — los clients entran por API key y `routes/client-api.php` no expone reservas — pero conviene decidirlo antes de que exista el primer client user.
