# Pase de MySQL a PostgreSQL 17

Estado al 2026-10-08: **dev y producción corren sobre Postgres 17. MySQL ya no
existe en ningún entorno**: contenedores y volúmenes eliminados ese mismo día.
Quedan dumps finales: `~/backups/hotelignite-mysql-local-final-*.sql.gz` en la
máquina de dev y `~/backups/daily/db-2026-10-08-1258.sql.gz` en el VPS.

## Extensiones

Imagen propia `hotelignite/pgsql:17-3.6-pgvector0.8.7`, construida desde
`docker/postgres/Dockerfile` en dev y en el VPS: `postgis/postgis:17-3.6-alpine`
(= `postgres:17-alpine` + PostGIS; mismo datadir, misma versión de ICU) más
pgvector 0.8.7 compilado. Las migraciones `2026_10_08_000010` y `000011` crean:

- `unaccent` (contrib): `unaccent('Embarcación')` → `Embarcacion`.
- `postgis` 3.6: tipos `geometry`/`geography` y funciones `ST_*`. Crea la tabla
  `spatial_ref_sys`, que Laravel ya excluye de `db:wipe`.

- `vector` (pgvector 0.8.7): tipo `vector(n)` e índices HNSW/IVFFlat para
  búsqueda semántica. Sin columnas todavía: la dimensión depende del modelo de
  embeddings que se elija.

Habilitarlas no cambia ninguna búsqueda todavía (ver Pendiente). Al subir la
imagen base, revisar que la versión de clang/llvm del Dockerfile coincida con la
de la imagen (pgvector necesita la misma para el bitcode JIT).

## Cutover de producción (2026-10-08, hecho)

- Servicio `pgsql` → contenedor `hi-pgsql` (`postgres:17-alpine`, ICU es-AR,
  `shared_buffers=64MB`, `max_connections=40`), volumen `hotelignite-prod_pg_data`.
- `DB_CONNECTION: pgsql` lo fija el `environment:` del compose (pisa al `.env`).
- MySQL (`hi-db`) eliminado junto con el volumen `hotelignite-prod_db_data`.
- Backups previos en `~/backups/` del VPS: dump MySQL verificado (`daily/db-2026-10-08-1258.sql.gz`)
  y dump Postgres post-cutover (`pgsql-postcutover-2026-10-08-1314.sql.gz`).
- Copia: 68 tablas, 15.081 filas, todos los conteos iguales.
- Ya no hay rollback a MySQL en caliente: volver exigiría restaurar el dump
  MySQL en un contenedor nuevo y perder lo escrito después del cutover.

## Qué cambió

### Migraciones: esquema base nuevo

`database/migrations/` tiene 9 migraciones (`2026_10_08_00000{1..9}_*`) que
reconstruyen el esquema real de la base MySQL de dev con el Schema builder, sin
SQL crudo. Las 75 anteriores están en `database/migrations-mysql-legacy/`, sólo
como referencia: no corrían desde cero y dependían de MySQL (`DB::unprepared`,
`MODIFY`, `information_schema`, `sql_mode`).

Verificado contra la base MySQL de dev:

| | MySQL | Postgres |
|---|---|---|
| Tablas (sin `migrations`, `oauth_*`, `password_resets`) | 68 | 68 |
| Columnas | 729 | 729 |
| Foreign keys | 28 | 28 (mismas) |
| Índices únicos | iguales | iguales |
| `migrate` → `migrate:refresh` | — | sin errores |

Decisiones de traducción (también documentadas en el docblock de la 000001):

- `char(n)` → `varchar(n)`. El `char` de Postgres rellena con espacios al leer.
- `tinyint` usado como flag → `boolean` sólo donde el modelo lo castea o el código
  lo consulta con `true`/`false`. Los demás quedan `smallint`, porque si no la API
  pasaría a devolver `true` donde hoy devuelve `1`.
- Las fechas `NOT NULL DEFAULT '0000-00-00'` pasan a nullable: 22 columnas, todas
  en tablas legacy, más `reservations.comment`.
- `int unsigned` de montos → `bigint`, porque el `integer` de Postgres es con signo.
- Postgres no indexa las FKs solo: los índices que MySQL creaba implícitamente
  están declarados a mano.
- `inquiries.accommodation_id`: `char(36)` → `bigint`. El scope de tenancy hace
  `IN (select accommodations.id)` y Postgres no compara varchar con bigint.
- `reservation_id` de las tablas legacy (`reservation_rooms`, etc.) → `varchar(36)`,
  el tipo de `reservations.id`.
- `reservations.id` es `varchar(36)` y no `uuid`: las 1356 reservas legacy tienen
  ids numéricos ('1', '2', ...).
- `invitations.pending_*` siguen siendo columnas generadas (`storedAs`), que
  Postgres soporta.
- No se crean las `oauth_*` (Passport ya no está en composer). `password_resets`
  se reemplaza por `password_reset_tokens`, que es la que usa `config/auth.php`.

### Código

- `ClientApiUsage::hit()`: el `ON DUPLICATE KEY UPDATE` crudo pasa a `upsert()`.
- Búsquedas: `where(col, 'like', …)` → `whereLike()` en 16 archivos. En Postgres
  `like` distingue mayúsculas; `whereLike` compila a `ilike` (y queda igual en MySQL).
- `StoreInquiryRequest`: `accommodation_id` valida `integer`.
- Desempate por `id` en `AccountController@index` y `ReservationRepository`.
  Ordenaban por columnas con empates y la paginación no era determinística.

### Infra de dev

- `docker-compose.yml`: servicio `pgsql` (`postgis/postgis:17-3.6-alpine`, puerto host 5433,
  datadir `docker-pgsql/data`) inicializado con collation ICU `es-AR`. Sin eso,
  la imagen alpine ordena por bytes y `ORDER BY name` difiere de MySQL.
- `docker-api/Dockerfile` y `docker/Dockerfile` instalan `pdo_pgsql`. La de prod
  también trae `postgresql-client`.

## Copia de los datos (histórico)

La hizo `php artisan db:copy-from-mysql`, un comando de un solo uso que se
eliminó junto con MySQL (está en el historial de git, commit `b0aafcf`). Copiaba
en orden de FKs, pasaba las fechas cero a NULL y los 0/1 a boolean, movía las
secuencias al máximo `id` y comparaba conteos tabla por tabla.

En dev se copiaron 68 tablas y 15.114 filas. Comprobaciones hechas:

- conteos iguales en las 68 tablas;
- diff fila por fila de `guests`, `accommodations` y `reservations`: sin diferencias;
- la suite (87 tests) pasa en Postgres y en MySQL;
- 7 respuestas de la API comparadas en los dos motores: JSON idéntico byte a byte.


## Pendiente

1. **Búsquedas sin acentos.** `whereLike` ya ignora mayúsculas, pero no acentos:
   "embarcacion" no encuentra "Embarcación", y en MySQL (`*_ci`) sí. La extensión
   `unaccent` ya está; falta usarla en las búsquedas (`unaccent(col) ILIKE
   unaccent(?)`), idealmente con un índice de expresión, o sumar `pg_trgm` si
   además se quiere búsqueda difusa.
2. **Geolocalización.** Hecho (2026-10-08, migración `000012`): `accommodations.location`
   es `geography(Point, 4326)` generada desde `latitude`/`longitude` (siguen siendo
   varchar), con índice GiST. Coordenadas inválidas o fuera de rango → `NULL`. Lo usa
   "En los alrededores" (`docs/points-of-interest-plan.md`). Quedan 5 alojamientos de
   dev con coordenadas mal cargadas (ids 3, 10, 18, 22, 76) por corregir a mano.
3. **`.env` del VPS**: dice todavía `DB_CONNECTION=mysql`. La app no lo usa (lo
   pisa el compose), pero `backup.sh` sí: hasta cambiarlo a `pgsql` el script
   intenta respaldar `hi-db`, que está apagado. El guard bloquea editarlo desde
   el agente; hay que hacerlo a mano. Después, cron de `backup.sh` con destino offsite.
4. **FKs sobre `accommodation_id` / `account_id`.** El motivo para no tenerlas
   (signed vs unsigned) ya no existe en Postgres. Para agregarlas antes hay que
   limpiar huérfanos: por ejemplo, 37 filas de `reservation_rooms` apuntan a
   reservas que no existen.
5. **Tablas legacy** (`009_create_legacy_pms_tables`): se copiaron para no
   perder histórico. Archivarlas y dropearlas en una ventana aparte.
6. `database/migrations-mysql-legacy/` se puede borrar cuando ya no sirva como
   referencia; no se ejecuta.
