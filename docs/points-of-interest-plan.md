# Puntos de interés (POI)

Catálogo global de lugares de interés por ciudad y categoría (restaurantes,
edificios emblemáticos, aeropuertos, farmacias…), cargado por el staff desde el
CRM y mostrado como "En los alrededores" de cada alojamiento usando PostGIS.

Decisiones tomadas el 2026-10-08:

- **MVP: sólo el staff de plataforma carga** (CRM). El hotelero ve los cercanos
  en el PMS, de sólo lectura. Personalización por alojamiento → fase 2.
- Taxonomía de 2 niveles sembrada por seeder (abajo), editable sólo por `platform`.
- `accommodations` gana una columna `location` generada (paso 1).

## Estado (2026-10-08)

MVP implementado, en dev, sin desplegar:

| Pieza | Dónde |
|---|---|
| `accommodations.location` generada + GiST | migración `2026_10_08_000012` |
| `poi_categories`, `points_of_interest` | migración `2026_10_08_000013` |
| Taxonomía base (8 raíces, 37 hojas) | `PoiCategorySeeder` (en `DatabaseSeeder`) |
| CRUD + `nearby-points` | `Admin\V1\{PoiCategory,PointOfInterest,AccommodationNearbyPoint}Controller`, `PointOfInterestRepository` |
| Tests | `tests/Feature/PointOfInterestTest.php` (26) |
| CRM | `/points-of-interest`, `/settings/poi-categories` |
| PMS | pestaña "Alrededores" en la página del hotel |

Para producción, además de `migrate`: `php artisan db:seed --class=PoiCategorySeeder`
(es `insertOrIgnore`: re-correrlo no pisa lo editado desde el CRM). Las
coordenadas basura de `accommodations` quedan con `location = NULL` y esos
alojamientos muestran "sin coordenadas" en "Alrededores" hasta que se corrijan.

## Nombre

`PointOfInterest` / `points_of_interest`. "POI" es el término de mapas y OTAs;
"atractivo turístico" excluye servicios (farmacias, bancos, aeropuertos). En la
UI: "Lugares de interés" (CRM) y "En los alrededores" (ficha del alojamiento,
como el "What's nearby" de Booking).

## Esquema

### `location` generada, sin paquete espacial

`latitude`/`longitude` siguen siendo las columnas que se escriben; Postgres
deriva `location geography(Point,4326)` con una columna generada (`storedAs`).
Así no hace falta `laravel-eloquent-spatial` ni casts de WKB ni sincronizar dos
representaciones: los FormRequests y Resources siguen hablando en números.
`location` va en `$hidden` (sale como WKB hexadecimal).

En `accommodations` las coordenadas son varchar legacy con default `'0'`, y hay
basura real (dev, 2026-10-08): `'0'`, lat/lng invertidas (ids 3, 10), signos
cambiados (18), longitud `-195.41` (22) y `123123` (76). La expresión no puede
fallar con eso —tumbaría el `ALTER TABLE` y cualquier INSERT posterior—, así que
valida formato y rango con `CASE` anidados (el `AND` de Postgres no garantiza
cortocircuito; `CASE` sí) y devuelve `NULL` si algo no cierra. `0,0` también es
`NULL`. Las invertidas caen en rango válido y quedan como un punto erróneo: hay
que corregirlas a mano desde el PMS.

`nearby-points` devuelve también `origin` (lat/lng leídos de `location`), para
que el frontend centre el mapa en el punto que realmente se usó para medir.

### `poi_categories`

```
id, parent_id (self, null = raíz), slug unique, name, icon (lucide),
sort_order, default_radius_m (null en hojas → hereda de la raíz),
nearest_limit (null; si tiene valor, la hoja se busca por KNN sin radio),
enabled, timestamps
```

Un POI siempre referencia una **hoja**.

### `points_of_interest`

```
id, city_id (integer, FK cities), poi_category_id (FK hoja),
name, slug unique, description, address, phone, website,
latitude numeric(9,6) null, longitude numeric(9,6) null,
location geography(Point,4326) generada,
is_featured, enabled, source (manual|osm|google), external_id,
timestamps, softDeletes
unique(source, external_id) · GiST(location) · index(city_id, poi_category_id)
```

Un POI sin coordenada (circuito, zona) se admite: aparece en el listado por
ciudad pero nunca en "cercanos".

## Taxonomía semilla (`PoiCategorySeeder`, upsert por slug)

| Raíz (radio) | Hojas |
|---|---|
| `food-drink` Gastronomía (1 km) | restaurant, grill, cafe, bar, bakery-ice-cream, winery |
| `attractions` Atracciones y cultura (3 km) | landmark, museum, church, square-park, viewpoint, historic-site, theater |
| `nature` Naturaleza y aire libre (30 km) | nature-reserve, water-feature, trail, adventure |
| `shopping` Compras (2 km) | mall, market, supermarket, local-crafts |
| `entertainment` Entretenimiento (2 km) | folk-show (peñas), nightclub, casino, cinema, venue |
| `transport` Transporte (5 km) | airport (KNN, los 3 más cercanos), bus-station, train-station, car-rental |
| `health` Salud (3 km) | hospital, pharmacy, emergency |
| `services` Servicios (1 km) | bank-atm, currency-exchange, tourist-office, gas-station |

Las peñas van en entretenimiento (es el espectáculo); categoría secundaria → fase 2.

## API (`/api/admin/v1`)

| Método | Ruta | Acceso |
|---|---|---|
| GET | `poi-categories` | cualquier usuario del panel (selectores) |
| POST/PUT/DELETE | `poi-categories[/{category}]` | `platform` |
| GET | `points-of-interest` `?city_id&category_id&q&featured&has_location&enabled&per_page` | cualquier usuario del panel |
| GET | `points-of-interest/{poi}` | ídem |
| POST/PUT/DELETE | `points-of-interest[/{poi}]` | `platform` |
| GET | `accommodations/{accommodation}/nearby-points` `?limit_per_group=` | `AccommodationPolicy@view` |

Para portales (`/api/client/v1`, API key): `GET accommodations/{slug}/nearby-points`
`?limit_per_group=`, scopeado con `Accommodation::visibleTo` (ajeno → 404). Misma
búsqueda, con `PublicPointOfInterestResource` (sin `source`, `external_id`,
`enabled` ni timestamps) y la categoría reducida a `slug`/`name`/`icon`. Lo
consume la ficha del alojamiento de TuriNorte ("Qué hay alrededor").

- `q` busca con `unaccent(name) ILIKE unaccent(?)`.
- Borrar una categoría con hijas o con POIs → 409.
- `nearby-points`: una SQL con `ST_DWithin` por el radio de la categoría
  (`COALESCE(hoja, raíz)`), `ST_Distance` y `ROW_NUMBER() OVER (PARTITION BY
  raíz ORDER BY is_featured DESC, distancia)`. Las hojas con `nearest_limit`
  (aeropuerto) van aparte por KNN (`ORDER BY location <-> punto LIMIT n`).
  Respuesta agrupada por categoría raíz con `distance_m` y `walk_min`
  (distancia × 1,3 / 80 m/min, sólo < 1,5 km). Alojamiento sin `location` →
  grupos vacíos y `has_location: false`.

## Frontends

- `crm/`: sección "Lugares de interés" (listado con filtros + formulario con
  mapa Leaflet para elegir el punto) y `settings/poi-categories`.
- `pms/`: panel "En los alrededores" en la página del hotel, sólo lectura.

## Fase 2

- Pivot `accommodation_poi` (`hidden`, `recommended`, `note`, `sort_order`)
  para que el hotelero oculte o recomiende puntos del catálogo; puntos propios
  del alojamiento.
- Imágenes del POI (morph `images`) con la misma lógica que las del alojamiento.
- Importador OSM (ODbL: exige atribución). Google Places no permite cachear
  datos salvo el Place ID.
- Horarios estructurados, rango de precio, accesibilidad, polígonos/rutas.
- Carga por clients de gobierno con moderación.
- `nearby-points` como tool del MCP del viajero.
