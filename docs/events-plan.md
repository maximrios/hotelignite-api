# Eventos y carga de contenido turístico por clients

Agenda de eventos por ciudad (festivales, peñas, ferias, congresos), cargada por
el staff desde el CRM **y por los clients B2B desde `clients/`**, que además
ganan la carga de puntos de interés (docs/points-of-interest-plan.md). Se cruza
con la ubicación y la estadía del alojamiento: "durante tu estadía, a 2 km".

Decisiones del 2026-10-08:

- **Videos: sólo embeds de YouTube.** No se suben archivos de video.
- **Recurrencia semanal en el MVP.** Un evento es un rango de fechas, o se repite
  ciertos días de la semana dentro de un rango (abierto o cerrado).
- **Sede opcional**: un evento puede apuntar a un punto de interés.
- **Cargan el staff y los clients**, eventos y puntos de interés.

## Estado (2026-10-08)

MVP implementado en dev, sin desplegar.

| Pieza | Dónde |
|---|---|
| Migración (`events`, `event_categories`, `media`, `points_of_interest.client_id`) | `2026_10_08_000014` |
| Categorías base (8) | `EventCategorySeeder` (en `DatabaseSeeder`) |
| Modelos | `Event`, `EventCategory`, `Media`; traits `HasUniqueSlug`, `OwnedByClient` |
| Permisos | `CatalogContentPolicy` (POI y eventos); `client_id` siempre desde el token |
| API staff | `Admin\V1\{Event,EventCategory,EventMedia,AccommodationNearbyEvent}Controller` |
| API client | `ClientPanel\V1\{PointOfInterest,Event}Controller` (+ `EventMediaController` compartido) |
| Tests | `EventTest` (15), `ClientContentTest` (8) |
| CRM | `/events`; "Cargado por" en eventos y lugares |
| Portal | `/points-of-interest`, `/events` (sólo lo propio) |
| PMS | "Agenda cercana" en la pestaña Alrededores |

Para producción: `migrate` y `php artisan db:seed --class=EventCategorySeeder --force`
(el guard lo bloquea: lo corre Maxi). En Vercel, `crm` y `clients` necesitan
`CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` (y opcional
`CLOUDINARY_EVENTS_FOLDER`, default `hotelignite/events`) para subir imágenes.

Detalles de implementación que no se deducen del esquema:

- El dueño se chequea en `authorize()` de las requests del client y de media,
  **antes** de validar: si no, editar algo ajeno con datos inválidos devolvía 422
  (confirmando que existe) en vez de 403.
- El client no fija `is_featured`, `source` ni `external_id`: las requests de
  `ClientPanel` los sacan de las reglas, así que `validated()` nunca los trae.
- "Hoy" para la agenda es `Event::today()`, en `config('app.destination_timezone')`.

## Quién escribe qué

| | Staff (`platform`, CRM) | Client (`client`, portal) | Hotelero (`account`, PMS) |
|---|---|---|---|
| Leer catálogo | todo | todo (`admin/v1`, GET) | todo (`admin/v1`, GET) |
| Crear | en cualquier ciudad | en cualquier ciudad | — |
| Editar / borrar | todo | **sólo lo propio** | — |
| Taxonomías | sí | — | — |

- **Propiedad**: `client_id` en `points_of_interest` y `events`. `NULL` = cargado
  por el staff. El staff puede editar lo de un client (curaduría); el client no
  toca lo del staff ni lo de otro client.
- **Sin jurisdicción en el MVP** (decisión del 2026-10-08): un client puede
  cargar en cualquier ciudad. No hay relación client ↔ ciudad en el modelo, y
  derivarla de las ciudades de su padrón (`accommodation_client`) es una
  inferencia que no siempre vale: una cámara o una agencia opera en ciudades
  donde todavía no tiene asociados. Ver Fase 2.
- **Rutas**: el client escribe por `client-panel/v1` (fuera de
  `client.readonly`, tenencia desde el token). `admin/v1` sigue siendo de sólo
  lectura para él.

## Esquema

### `event_categories`

Un nivel, sembrado por `EventCategorySeeder` (`insertOrIgnore` por slug):
música, festivales y fiestas populares, gastronomía, cultura y arte, deportes,
ferias y congresos, religiosos, familia y niños.

### `events`

```
id, city_id (FK), event_category_id (FK), point_of_interest_id (FK, null)
client_id (FK, null = staff)
name, slug unique, summary (≤300), description
start_date, end_date (null = un solo día; en semanales, null = sin fin)
start_time, end_time (null = todo el día)
recurrence: none | weekly · weekdays jsonb [1..7] (ISO, 1 = lunes; sólo weekly)
status: scheduled | postponed | cancelled | sold_out
venue_name, address, latitude, longitude, location (generada)
price_type: free | paid | unknown · price_from, price_to, currency (ARS)
ticket_url, organizer_name, organizer_email, organizer_phone
links jsonb [{type, url}] · is_featured · enabled
timestamps, softDeletes
```

- **Fechas sin zona**: `date` + `time` en hora local del destino
  (`America/Argentina/Salta`). Un evento a las 21 h es a las 21 h para quien lo
  lea desde cualquier lado; guardarlo como instante UTC obliga a convertir en
  cada lectura y rompe con los eventos de todo el día.
- **Ubicación efectiva**: la propia o, si no tiene, la de su sede
  (`COALESCE(events.location, poi.location)`). No se copian coordenadas del POI:
  se desincronizarían.
- **Ocurre en [desde, hasta]**: `none` → los rangos se superponen. `weekly` →
  además, algún día del cruce cae en `weekdays` (`generate_series` sobre el cruce,
  acotado). La misma regla filtra el listado y "cercanos".

### `media` (polimórfica)

```
id, mediable_type, mediable_id, type (image | video), provider (cloudinary | youtube)
url, provider_id (id de YouTube), thumbnail_url, alt, order, timestamps
```

Tabla nueva en vez de extender `images` (la usan alojamientos y habitaciones).
Para YouTube se guarda la URL normalizada y el id; **nunca HTML de un iframe**: el
frontend arma el embed desde el id con `youtube-nocookie.com`. Las imágenes
suben directo a Cloudinary con firma, como en el PMS.

### `links`

jsonb con tipos cerrados: `website`, `instagram`, `facebook`, `tiktok`, `x`,
`youtube`, `whatsapp`, `spotify`. Cada URL se valida contra el dominio de su red.
Instagram y TikTok se muestran como botones, no embebidos (scripts de terceros).

## API

`admin/v1` (lectura abierta, escritura `platform`):

```
GET            event-categories
GET            events                ?city_id&category_id&from&to&status&q&enabled&mine
GET            events/{event}
POST/PATCH/DEL events[/{event}]
POST           events/{event}/media             (image: url · video: url de YouTube)
PATCH          events/{event}/media/reorder
DELETE         events/{event}/media/{media}
GET            accommodations/{accommodation}/nearby-events  ?from&to&radius_m
```

`client-panel/v1` (client, tenencia del token):

```
GET/POST       points-of-interest               index = los propios
GET/PATCH/DEL  points-of-interest/{poi}         sólo los propios
GET/POST       events
GET/PATCH/DEL  events/{event}
POST/PATCH/DEL events/{event}/media[...]
```

`nearby-events`: radio por defecto 15 km (tope 50 km), ventana por defecto hoy →
+30 días, excluye cancelados, ordena por destacado y próxima fecha.

## Frontends

- `crm/`: "Eventos" (listado, alta/edición con recurrencia, sede, links y
  multimedia). Columna "Cargado por" en eventos y lugares.
- `clients/`: "Lugares de interés" y "Eventos" con lo propio.
- `pms/`: la pestaña "Alrededores" suma "Agenda cercana" (próximos 30 días).

Imágenes: `crm/` y `clients/` firman la subida a Cloudinary igual que el PMS
(`/api/cloudinary/sign`). Necesitan `CLOUDINARY_*` en su entorno (Vercel).

## Fase 2

- **Jurisdicción de los clients.** Hoy un client carga en cualquier ciudad, así
  que el catálogo global queda expuesto a lo que cargue cualquier client. Opciones:
  una relación explícita `client_city` (o por provincia) que asigne el staff
  desde el CRM, o moderación previa a publicar. La primera es la natural para
  municipios y entes de turismo.
- CRUD de `event_categories` desde el CRM (hoy sólo seeder).
- Moderación de lo que cargan los clients antes de publicarse.
- Recurrencias más complejas (mensual, fechas sueltas).
- Eventos en `/api/client/v1`, en la web pública del client y en el MCP.
- Media para puntos de interés (la tabla ya es polimórfica).
