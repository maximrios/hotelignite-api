# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
php artisan serve          # Start dev server
php artisan migrate        # Run migrations
php artisan test           # Run all tests
php artisan test --filter=TestClassName  # Run a single test
./vendor/bin/pint          # Code formatting
composer install           # Install dependencies
```

## Architecture

### Request lifecycle

Every request flows: `Route → Controller → Repository (via Interface) → Model → Resource`

- **Controllers** (`app/Http/Controllers/Api/V1/`) inject a repository interface via constructor DI and delegate all data work to it.
- **Repository interfaces** (`app/Repositories/Contracts/`) define the contract.
- **Repositories** (`app/Repositories/`) implement the interface with Eloquent queries and return Resource or ResourceCollection objects.
- **Resources** (`app/Http/Resources/V1/`) shape the JSON output. Each entity has a `*Resource` (single) and `*ResourceCollection` (list).
- **Form Requests** (`app/Http/Requests/`) handle validation — one class per operation (`Store*`, `Update*`, `Destroy*`, `Search*`, `Get*`).
- **Bindings** are registered in `app/Providers/RepositoryServiceProvider.php`. When adding a new repository, add the Interface→Repository binding there.

### Routes

- `routes/api.php` — main V1 routes, all under `/api/v1/`, protected by `auth:sanctum` + `throttle:api` except:
  - `POST /api/auth/login` — public
  - `GET /api/v1/channels` — public (no auth)
- `routes/pms.php` — PMS bookings endpoint, uses `client` middleware (OAuth client credentials, not Sanctum)
- `routes/auth.php` — standard Laravel auth scaffolding routes

### Domain model map

#### Cuentas (tenants hoteleros)
- `Account` — el hotelero dueño de uno o más `Accommodation`. Usa SoftDeletes. Tiene `plan`, `accountType`, `accommodations` y `users`.

**El CRUD de `Account` es `/api/admin/v1/accounts`** (`Api\Admin\V1\AccountController`):
tiene `AccountPolicy`, pagina como el resto de admin y su Resource no expone
`accounts.token`.

Había un segundo CRUD legacy en `/api/v1/accounts`, **borrado el 2026-08-31**. Si
lo ves referenciado en un doc viejo: devolvía el `token` de la cuenta (secreto de
40 caracteres) en todas las respuestas, su `total` de paginación ignoraba los
filtros —mentía al buscar— y su `destroy` recibía el id por el cuerpo de un
`DELETE /accounts` sin id en la ruta. No lo consumía ningún frontend.

Ojo al borrar: `accommodations.account_id` y `users.account_id` no tienen FK (el
legacy los tiene como `int`, incompatible con el `bigint unsigned` de
`foreignId()`), así que la base no impide dejar huérfanos. `AccountPolicy::delete()`
solo autoriza cuentas vacías por eso.

#### Alojamiento
- `Accommodation` — entidad central del sistema hotelero. Se busca por `slug`. Tiene: `roomTypes`, `images` (polimórfico), `descriptions` (multilenguaje), `services` (M2M vía `AccommodationService`), `policies` (M2M vía `AccommodationPolicy` con pivot `language_id` y `description`), `state`, `city`, `type`, `plan`. **La suscripción es por alojamiento** (`accommodations.plan_id`), no por cuenta — una misma cuenta puede tener propiedades en tiers distintos. Los permisos (entitlements) se consultan con `$accommodation->hasFeature('slug')` / `featureLimit('slug')`. `allow_bookings` deriva de `allowsOnlineBookings()` (= `hasFeature('booking_engine')`); **ya no se usa `plan_id === 1`**.
- `AccommodationType` — tipo de alojamiento (hotel por estrellas, hostel, etc.). Los tipos 1–5 se agrupan como "hoteles por estrellas" en los filtros.
- `AccommodationDescription` — descripción multilenguaje del alojamiento (filtrado por `language_id`, ej. `'es'`).
- `AccommodationService` — pivot Accommodation ↔ Service.
- `AccommodationPolicy` — pivot Accommodation ↔ Policy, con `language_id` y `description` en el pivot.
- `RoomType` — tipos de habitación de un alojamiento. Tiene: `images` (polimórfico), `descriptions` (`RoomTypeDescription`), `services` (M2M vía `RoomTypeService`), `category` (`RoomCategory`).
- `RoomTypeDescription` — descripción multilenguaje del tipo de habitación.
- `RoomTypeService` — pivot RoomType ↔ Service.
- `RoomCategory` — categoría de habitación (ej. suite, doble, etc.).
- `Room` — habitación física individual.
- `Service` — servicio ofrecido (wifi, piscina, etc.), compartido entre alojamientos y tipos de habitación.
- `Policy` — política del alojamiento (cancelación, mascotas, etc.).

#### Imagen
- `Image` — modelo polimórfico (`imageable_type` / `imageable_id`). Lo usan `Accommodation`, `RoomType`, `Tour` y `City` vía `morphMany`. Tiene `url`, `alt` y `order`.

#### Reservas (flujo de dos pasos)
- `Booking` — pre-reserva iniciada por el huésped (usa UUID). Contiene fechas (`checkin`/`checkout` en formato `d/m/Y` en entrada, `Y-m-d` en BD), `adults`, `childrens`, `accommodation_id`, `room_id`, `tour_id`. En un segundo paso se actualiza con datos personales (`name`, `lastname`, `email`, `phone`).
- `Reservation` — reserva confirmada (usa UUID). Vincula `Accommodation`, `Guest`, `Channel` y `Status`. Tiene computed attribute `pax` = `adults + childrens`.
- `Guest` — perfil del huésped. Tiene `full_name` como computed attribute (`first_name + last_name`) y `hasMany Reservation`.
- `Status` — estado de una reserva.

#### Distribución y turismo
- `Channel` — canal de distribución (OTA, directo, agencia, etc.). Tiene `hasMany Tour`.
- `Tour` — producto turístico. Pertenece a un `Channel`, tiene `images` polimórficas.
- `TravelAgency` — agencia de viajes. Entidad independiente (aún sin relaciones definidas en el modelo).

#### Geografía
- `City` — ciudad buscada por `slug`. Tiene `accommodations`, `images` polimórficas y `state`.
- `State` — estado/provincia al que pertenece un `Accommodation`.

**Las dos tablas tienen tamaños contraintuitivos**: `cities` son ~38 filas (solo
las que se operan), pero `states` son **4119, de ~246 países** — es un catálogo
mundial del dump legacy, no las provincias argentinas. Argentina son 24
(`country_id = 10`, resoluble por `iso_code` en `countries`).

Consecuencia para cualquier selector: la provincia hay que **filtrarla por país**
o la lista es inservible. `Api\Admin\V1\StateController` lo hace vía
`config('api.catalog_country_iso')`. Las ciudades, en cambio, entran enteras —
pero `Api\Admin\V1\CityController` igual busca y topea, porque la tabla crece.

Ojo con `cities.state_id`: es legacy `int NOT NULL DEFAULT 0` y sin FK, así que
"sin provincia" está guardado como `0`, no como `NULL`.

#### Planes y suscripciones (entitlements)
Modelo de tipo SaaS: **el plan se contrata por alojamiento**, y los permisos se definen como datos (no con `if` por slug en el código).
- `Plan` — tier comercial (`free`, `starter`, `pro`, `enterprise`). Tiene `price` (centavos), `currency`, `billing_period`, `subtitle`, `sort_order`, `is_public`. Relación `features()` (M2M vía `plan_feature`, con pivot `limit`). Helpers `hasFeature($slug)` / `featureLimit($slug)`.
- `Feature` — funcionalidad del catálogo (`slug`, `module`, `type` = `boolean`|`limit`). Agrupadas por `module`: `core`, `booking`, `distribution`, `operations`, `billing`, `platform`.
- `plan_feature` — pivot Plan ↔ Feature; `limit` (null = ilimitado / no aplica a features `boolean`).
- **Tiers acumulativos:** cada plan hereda las features del anterior. Sembrados en `PlanSeeder` / `FeatureSeeder` / `PlanFeatureSeeder` (idempotentes, `updateOrInsert` por slug).
- **Fuente de verdad:** `accommodations.plan_id` (entitlements por propiedad). `accounts.plan_id` es solo default/facturación, **no** define permisos. Para chequear acceso usar `$accommodation->hasFeature('pms')`, etc. — nunca comparar `plan_id` contra un número.

### PMS (Property Management System)

`routes/pms.php` expone endpoints para integración máquina-a-máquina con el PMS frontend, bajo middleware `client` (OAuth client credentials, distinto de Sanctum). El controlador es `app/Http/Controllers/Api/Pms/BookingController.php`, separado de los controladores V1.

### Naming conventions

- Pivot/join models usan nombres descriptivos: `AccommodationService`, `AccommodationPolicy`, `RoomTypeService`, `RoomTypeDescription`.
- Dos repositorios tienen un typo histórico (falta la 'o'): `AccommodationRespository` y `TravelAgencyRespository` — respetarlo al referenciarlos.
- Los recursos siempre van en par: `FooResource` + `FooResourceCollection`.
- Descripciones y políticas son multilenguaje; el lenguaje por defecto en las consultas es `'es'`.
