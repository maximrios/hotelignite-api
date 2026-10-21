# Experiencias

Excursiones, actividades y circuitos (day tours, trekking, enoturismo, talleres)
que se cargan desde el CRM y los clients B2B, y se publican en el portal de cada
client (turinorte es el primero). Reemplaza a la tabla legacy `tours`.

Decisiones del 2026-10-20:

- **Reemplaza a `tours` sin migrar datos.** Las 6 filas actuales (título + link a
  turismosalta.gov.ar) se descartan. `tours`, `city_tour` y sus endpoints se
  borran cuando turinorte deje de consumirlos.
- **Sin tabla de salidas en el MVP.** La disponibilidad es días de la semana +
  meses de temporada. No hay "próxima salida" con cupo.
- **`draft | published` para todos**, staff incluido. Lo que carga un client
  nace en `draft`.
- **El catálogo es por client.** Un portal muestra sólo las experiencias de su
  client (`client_id` = el client de la API key), publicadas. El CRM puede crear
  una experiencia y asignársela a un client; lo que el staff carga sin client no
  sale en ningún portal.
- **`inquiries` pasa a ser polimórfica** (alojamiento o experiencia) y se abre
  en `client/v1` para que el portal pueda crear consultas.

## Estado (2026-10-20)

Entrega 1 implementada en dev, sin desplegar.

| Pieza | Dónde |
|---|---|
| Migración (`experiences`, `experience_categories`, `city_experience`) | `2026_10_20_000001` |
| Categorías base (9) | `ExperienceCategorySeeder` (en `DatabaseSeeder`) |
| Modelos | `Experience`, `ExperienceCategory`; reusa `HasUniqueSlug`, `OwnedByClient`, `Media` |
| Permisos | `CatalogContentPolicy` (suma `Experience`) |
| API staff | `Admin\V1\{Experience,ExperienceCategory,ExperienceMedia}Controller` |
| Reglas | `ExperienceRules` (+ `client_id` sólo en las requests del staff) |
| Media | `StoreMediaRequest`/`ReorderMediaRequest` autorizan contra `event` o `experience` |
| Tests | `ExperienceTest` (12) |

Para producción: `migrate` y `php artisan db:seed --class=ExperienceCategorySeeder --force`
(el guard lo bloquea: lo corre Maxi).

Detalles de implementación que no se deducen del esquema:

- `duration_kind`: horas ≤ 5 → `medio`; > 5 y ≤ 24 (o 1 día) → `dia`; más →
  `varios`. La misma regla está en `Experience::durationKind()` y en el scope
  SQL `durationKind`, que es el que filtra.
- La ciudad de salida nunca queda en el pivote: se saca al guardar, también
  cuando una edición cambia la salida.
- `price_from` se limpia si `price_type` no es `paid`. `operating_days` y
  `season_months` vacíos se guardan como null (= siempre).
- `client_id` no es fillable: lo fija el repositorio, desde la request del staff
  o (entrega 6) desde el token del client, que tiene prioridad.

### Entregas 2, 4 y 5 (2026-10-20)

En dev, sin desplegar.

| Pieza | Dónde |
|---|---|
| CRM `/experiences` (listado con filtros, alta, edición, galería, baja) | `crm/app/(protected)/experiences`, `crm/components/experiences` |
| `client/v1/experiences` (`?city&category&duration_kind&featured&per_page`) y `/{slug}` | `Client\ExperienceController`, `PublicExperienceResource` |
| Tests | `ClientExperienceApiTest` (5) |
| Turinorte: `/experiences`, carruseles (home, destinos, micrositios), ciudad y conteos por destino sobre la API nueva; ficha `/experiences/[slug]` real | `turinorte/web/src/lib/experiences.ts`, `experienceItem.ts`, `app/(portal)/experiences/[experience]/page.tsx` |

- Turinorte ya no consume `tours`: se borraron su BFF (`/api/catalog/tours`) y los
  hooks `useGetTours`/`useGetTour`. En la API `tours` sigue vivo (y marcado legacy
  en `client-api.php`) hasta que el deploy de turinorte esté en producción.
- La ficha convierte por contacto directo con el operador (reserva, WhatsApp,
  teléfono, email). El formulario de consulta llega con la entrega 3.
- `meeting_point` en el resource público es la ubicación efectiva (la propia o la
  del POI enlazado).

### Entregas 3 y 6 (2026-10-20)

En dev, sin desplegar.

| Pieza | Dónde |
|---|---|
| Migración: `inquiries.accommodation_id` → `inquirable_type`/`inquirable_id` (backfill), `client_id` (portal de origen), `checkin` nullable | `2026_10_20_000002` |
| `Inquiry` con `morphTo` y `scopeVisibleTo` propio (ya no usa `BelongsToAccommodation`) | `app/Models/Inquiry.php` |
| `POST client/v1/inquiries` (ability **`inquiry:create`**) | `Client\InquiryController`, `Client\StorePortalInquiryRequest` |
| `client-panel/v1/experiences` (CRUD + media) y `inquiries` (GET, PATCH status) | `ClientPanel\V1\{Experience,Inquiry}Controller` |
| Tests | `InquiryTest` (8), `ClientPanelExperienceTest` (3) |
| Turinorte: formulario de consulta en la ficha (BFF `/api/catalog/inquiries`) | `ExperienceInquiryForm.tsx` |
| Clients: `/experiences` (lo propio, puede publicar y destacar) y `/consultas` | `clients/app/(protected)/{experiences,consultas}` |

- `inquirable_type` guarda la clase completa (`App\Models\Experience`), como
  `images` y `media`. **No registrar un morphMap global**: cambiaría cómo se
  guardan y buscan esas tablas.
- `InquiryResource` sigue mandando `accommodation_id` (derivado del morph) y `v1`
  sigue filtrando por él: el PMS no cambió.
- El client destaca lo suyo (`is_featured`): a diferencia de eventos y POI, sólo
  reordena su propio portal. No fija `client_id`, `source` ni `external_id`.
- **Para producción**: la key de turinorte necesita `inquiry:create`. Hoy no hay
  endpoint para editar abilities de una key existente: emitir una nueva desde el
  CRM (ya ofrece la ability) y cambiar `API_KEY` en Vercel. Antes de migrar,
  contar las filas de `inquiries` en `hi-pgsql`; el backfill las pasa todas.
- Nadie recibe un aviso cuando entra una consulta: el client la ve en su
  bandeja. Un email al client (y/o al operador) es lo siguiente.

## Diferencias con eventos y POI

| | Eventos / POI | Experiencias |
|---|---|---|
| Catálogo | global (todo el mundo lee todo) | **por client** |
| Publicación | `enabled` | `status: draft \| published` |
| Staff → client | `client_id` sólo lo fija el token | el staff **elige** `client_id` desde el CRM |
| Fechas | rango / semanal (evento) | permanente: días de operación + temporada |
| Ciudades | una (`city_id`) | salida + destinos que recorre (pivote) |

Lo demás se reutiliza tal cual: `HasUniqueSlug`, `OwnedByClient`, `SoftDeletes`,
`HasMedia` + tabla `media`, `links` validados por dominio (`Event::LINK_DOMAINS`),
`source`/`external_id`, subida firmada a Cloudinary en crm/clients.

## Esquema

### `experience_categories`

Un nivel, sembrado por `ExperienceCategorySeeder` (`insertOrIgnore` por slug):
excursiones, aventura, enoturismo, gastronomía, cultura y comunidades,
naturaleza y avistaje, trenes y rutas escénicas, bienestar, talleres.

### `experiences`

```
id, experience_category_id (FK), client_id (FK, null = staff sin asignar)
departure_city_id (FK)         ciudad de salida / base
point_of_interest_id (FK, null) punto de encuentro opcional
name, slug unique, summary (≤300), description
duration_value, duration_unit: hours | days
difficulty: easy | moderate | hard · min_age · group_min · group_max
languages jsonb ['es','en',...] · includes jsonb [str] · excludes jsonb [str]
what_to_bring (text)
meeting_point_name, address, latitude, longitude, location (generada)
price_type: free | paid | unknown · price_from · currency (ARS) · price_unit: person | group
operating_days jsonb [1..7] (ISO; null = todos los días)
season_months jsonb [1..12] (null = todo el año)
operator_name, operator_email, operator_phone, booking_url, links jsonb
status: draft | published · is_featured
source, external_id, timestamps, softDeletes
```

- **Operador ≠ dueño.** `client_id` es quién la carga y en qué portal sale;
  `operator_*` es quién la presta (un municipio carga la excursión de un guía).
- **`duration_kind`** (`medio` / `dia` / `varios`, lo que espera turinorte) se
  deriva en el resource: `hours` ≤ 5 → medio, `hours` > 5 → día, `days` ≥ 2 →
  varios, `days` = 1 → día. No se guarda.
- **Sin `rating` / `reviews`**: no hay sistema de reseñas. `badge` sale de
  `is_featured`.
- **Ubicación efectiva**: la propia o la del punto de encuentro, igual que eventos
  (`COALESCE(experiences.location, poi.location)`).

### `city_experience`

Destinos que recorre (además de la salida). Es lo que filtra un micrositio:
la excursión Salta → Cafayate aparece en `cafayate.turinorte.com`. El filtro
`?city=` matchea `departure_city_id` **o** el pivote.

### `inquiries` polimórfica

Hoy: `accommodation_id` + `checkin` obligatorio, tenencia con
`BelongsToAccommodation`, sólo escribible por `v1` (sanctum) y leída por el PMS.

```
+ inquirable_type ('accommodation' | 'experience', morphMap)
+ inquirable_id
- accommodation_id   (backfill → inquirable, después se borra)
  checkin            pasa a nullable; en experiencias = fecha deseada (opcional)
```

- Tenencia (`scopeVisibleTo`): `platform` todo; `account` las de sus
  alojamientos (como hoy); `client` las de sus experiencias + las de los
  alojamientos de su padrón.
- Las reglas de `StoreInquiryRequest` dependen del tipo: alojamiento exige
  `checkin`; experiencia, `adults` y fecha opcional.
- **Antes de migrar en producción**: contar filas de `inquiries` en `hi-pgsql`
  (en dev hay 0) para dimensionar el backfill.

## Quién escribe qué

| | Staff (CRM) | Client (portal `clients/`) | Portal público (API key) |
|---|---|---|---|
| Leer | todo | lo propio | lo propio, `published` |
| Crear | asignando o no un `client_id` | lo propio, nace `draft` | — |
| Editar / borrar | todo | sólo lo propio | — |
| Publicar | sí | sí, lo propio | — |
| Consultas | ve todas | las de sus experiencias | crea (`inquiry:create`) |

Lo de "el client publica lo propio" queda así en el MVP porque el catálogo es
por client: lo que publica sólo sale en su portal. Si después hay un portal que
agrega experiencias de varios clients, la moderación (publica el staff) se
vuelve necesaria.

## API

`admin/v1` (todo `platform`, lectura incluida: el catálogo es por client. Sólo
`experience-categories` es de lectura abierta):

```
GET            experience-categories
GET            experiences               ?client_id (id | none)&city_id&category_id&status&difficulty&duration_kind&featured&q
GET            experiences/{experience}
POST/PATCH/DEL experiences[/{experience}]          client_id aceptado sólo a platform
POST/PATCH/DEL experiences/{experience}/media[...]
```

`client-panel/v1` (client, tenencia del token):

```
GET/POST       experiences
GET/PATCH/DEL  experiences/{experience}
POST/PATCH/DEL experiences/{experience}/media[...]
GET            inquiries                 las de sus experiencias (y padrón)
```

`client/v1` (API key, portal):

```
GET   experiences           ?city&category&duration_kind   client_id = api client, published
GET   experiences/{slug}
POST  inquiries             ability inquiry:create; el inquirable tiene que ser visible al client
```

El resource público incluye `cities` (salida + destinos) para que turinorte no
consulte una vez por destino como hace hoy con `tours`.

## Entregas

1. **API**: migración, modelos, seeder de categorías, `admin/v1` + media, tests.
2. **CRM**: `/experiences` (listado con filtro por client y estado, alta/edición
   con selector de client, multimedia).
3. **Inquiry polimórfica**: migración + backfill, tenencia, `client/v1/inquiries`,
   el PMS sigue funcionando igual (test de regresión).
4. **`client/v1/experiences`**.
5. **Turinorte**: `/experiences` sobre la API nueva, detalle real (hoy es el
   template Triprex), formulario de consulta, micrositios. Después: borrar
   `tours`, `city_tour`, `TourRepository`, `client/v1/tours`, `v1/tours`.
6. **Clients**: carga propia y bandeja de consultas.

Producción: `migrate` + `db:seed --class=ExperienceCategorySeeder --force`
(lo corre Maxi por el guard).

## Fase 2

- Tabla de salidas (fecha, cupo, precio) y "próxima salida" real.
- Experiencias cerca de un alojamiento (PMS y ficha de turinorte).
- Itinerario: POIs que recorre (`experience_point_of_interest`).
- Experiencias en el MCP del viajero.
- `bookings.tour_id`: legacy, se evalúa al borrar `tours`.
