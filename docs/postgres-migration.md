# Pase de MySQL a PostgreSQL 17

Estado al 2026-10-08: **dev y producción corren sobre Postgres 17.**

## Cutover de producción (2026-10-08, hecho)

- Servicio `pgsql` → contenedor `hi-pgsql` (`postgres:17-alpine`, ICU es-AR,
  `shared_buffers=64MB`, `max_connections=40`), volumen `hotelignite-prod_pg_data`.
- `DB_CONNECTION: pgsql` lo fija el `environment:` del compose (pisa al `.env`).
- MySQL (`hi-db`) **apagado**: detrás del profile `mysql-legacy`, con
  `restart=no`. Volumen `hotelignite-prod_db_data` intacto.
- Backups previos en `~/backups/` del VPS: dump MySQL verificado (`daily/db-2026-10-08-1258.sql.gz`)
  y dump Postgres post-cutover (`pgsql-postcutover-2026-10-08-1314.sql.gz`).
- Copia: 68 tablas, 15.081 filas, todos los conteos iguales.
- Rollback: imagen `hotelignite/api:pre-pgsql`, `docker-compose.yml.bak-2026-10-08-premysql`
  y `.env.bak-2026-10-08-premysql` en `docker/`. Volver a MySQL pierde lo
  escrito después del cutover.

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

- `docker-compose.yml`: servicio `pgsql` (`postgres:17-alpine`, puerto host 5433,
  datadir `docker-pgsql/data`) inicializado con collation ICU `es-AR`. Sin eso,
  la imagen alpine ordena por bytes y `ORDER BY name` difiere de MySQL.
- `docker-api/Dockerfile` y `docker/Dockerfile` instalan `pdo_pgsql`. La de prod
  también trae `postgresql-client`.
- `config/database.php`: conexión `mysql_source` (variables `MYSQL_SOURCE_*`),
  que es el origen de la copia.

## Copiar los datos

```bash
php artisan migrate                      # sobre la base Postgres vacía
php artisan db:copy-from-mysql           # --truncate si ya tiene datos
```

El comando copia en orden de FKs, pasa las fechas cero a NULL y los 0/1 a
boolean, mueve las secuencias al máximo `id` y al final compara conteos tabla
por tabla. Si algún conteo no coincide, sale con error.

En dev se copiaron 68 tablas y 15.114 filas. Comprobaciones hechas:

- conteos iguales en las 68 tablas;
- diff fila por fila de `guests`, `accommodations` y `reservations`: sin diferencias;
- la suite (87 tests) pasa en Postgres y en MySQL;
- 7 respuestas de la API comparadas en los dos motores: JSON idéntico byte a byte.

Para volver atrás en dev: en `api/.env` poner `DB_CONNECTION=mysql`, `DB_HOST=db`,
`DB_PORT=3306` y `DB_USERNAME=root`. El contenedor `db` sigue arriba, pero no
recibe lo que se escribió después del pase.

## Pendiente

1. **Búsquedas sin acentos.** `whereLike` ya ignora mayúsculas, pero no acentos:
   "embarcacion" no encuentra "Embarcación", y en MySQL (`*_ci`) sí. Se resuelve
   con la extensión `unaccent`, o con `pg_trgm` + `unaccent` si además se quiere
   búsqueda difusa.
2. **`.env` del VPS**: dice todavía `DB_CONNECTION=mysql`. La app no lo usa (lo
   pisa el compose), pero `backup.sh` sí: hasta cambiarlo a `pgsql` el script
   intenta respaldar `hi-db`, que está apagado. El guard bloquea editarlo desde
   el agente; hay que hacerlo a mano. Después, cron de `backup.sh` con destino offsite.
3. **FKs sobre `accommodation_id` / `account_id`.** El motivo para no tenerlas
   (signed vs unsigned) ya no existe en Postgres. Para agregarlas antes hay que
   limpiar huérfanos: por ejemplo, 37 filas de `reservation_rooms` apuntan a
   reservas que no existen.
4. **Tablas legacy** (`009_create_legacy_pms_tables`): se copiaron para no
   perder histórico. Archivarlas y dropearlas en una ventana aparte.
5. Una vez hecho el cutover: borrar `mysql_source`, el comando de copia y
   `database/migrations-mysql-legacy/`.
