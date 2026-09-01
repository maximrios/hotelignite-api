# Deploy en el VPS — API HotelIgnite

Stack de producción: **Traefik** (TLS automático con Let's Encrypt, rate limit
y límite de conexiones en vuelo) → **nginx + php-fpm 8.4** (mismo contenedor) →
**MySQL 8** + **Redis** (cache y rate limiter). Cuatro servicios.

A diferencia de `docker-compose.yml` (desarrollo, en la raíz del monorepo), acá
**no hay bind mounts del código**: la imagen se construye con el código y el
`vendor/` adentro, y opcache corre con `validate_timestamps=0`. Para cambiar
código hay que reconstruir la imagen.

Todo lo de deploy vive en `api/docker/`:

| Archivo | Qué es |
|---|---|
| `docker/docker-compose.prod.yml` | Stack de producción (proyecto `hotelignite-prod`) |
| `docker/.env.production.example` | Plantilla de variables — copiar a `docker/.env.production` |
| `docker/Dockerfile` | Imagen única: php-fpm 8.4 + nginx + supervisord |
| `docker/entrypoint.sh` | Espera MySQL, migra (opcional) y cachea config/rutas/vistas |
| `docker/nginx.conf` | nginx de producción (gzip, cache, `/healthz`) |
| `docker/php.ini` | opcache, límites, errores al log |
| `docker/php-fpm.conf` | Pool fpm, slowlog |
| `docker/supervisord.conf` | Corre php-fpm y nginx dentro del contenedor |
| `.dockerignore` | Queda en `api/` porque ése es la raíz del contexto de build |

## 1. Requisitos en el VPS

- Docker Engine + plugin `compose` v2.
- Puertos **80** y **443** libres (los toma Traefik; si ya corre otro Traefik o
  nginx en el host, hay que apagarlo).
- DNS apuntando al VPS **antes** de levantar (Let's Encrypt valida por TLS-ALPN):
  `api.tudominio.com` → IP del VPS.

## 2. Configuración

```bash
cd api/docker
cp .env.production.example .env.production
chmod 600 .env.production
$EDITOR .env.production
```

Mínimo a completar:

- `API_DOMAIN`, `ACME_EMAIL`
- `DB_PASSWORD`, `DB_ROOT_PASSWORD` (largos y distintos)
- `APP_URL=https://api.tudominio.com`, `APP_DEBUG=false`
- `CORS_ALLOWED_ORIGINS` / `SANCTUM_STATEFUL_DOMAINS` con los dominios de
  `crm/`, `clients/` y `pms/`
- `APP_KEY`:
  ```bash
  docker compose --env-file .env.production -f docker-compose.prod.yml \
      run --rm --no-deps api php artisan key:generate --show
  ```
  (el entrypoint deja pasar de largo los comandos puntuales: no espera la base
  ni exige `APP_KEY` salvo cuando arranca el servicio)

`DB_HOST` y `DB_PORT` los fija el compose apuntando al servicio `db`: **no** los
pongas en el `.env.production`.

Para no repetir flags, parado en `api/docker/`:

```bash
alias dcp='docker compose --env-file .env.production -f docker-compose.prod.yml'
```

## 3. Primer deploy

```bash
dcp build
dcp up -d
dcp ps
dcp logs -f traefik   # ver la emisión del certificado
```

El contexto de build es `api/` (el compose usa `context: ..`), así que el
`.dockerignore` que manda es `api/.dockerignore`.

### Cargar la base

`php artisan migrate` **no funciona desde cero** en este repo: la migración
`2023_05_21_000015_create_reservations_table` crea una FK hacia `rooms`, tabla
que se crea recién en `2026_03_25_000002_create_rooms_table`. Por eso
`RUN_MIGRATIONS=false` viene por defecto.

El camino sano es importar un dump del entorno actual (incluida la tabla
`migrations`, así las nuevas migraciones se aplican encima):

```bash
# en la máquina de desarrollo
docker exec db mysqldump -uroot -p123456789 --single-transaction \
    --routines --triggers hotelignite > backups/hotelignite.sql

# copiar al VPS y restaurar (la redirección ocurre en el host)
dcp exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < backups/hotelignite.sql
```

Con el esquema cargado se puede poner `RUN_MIGRATIONS=true` para que cada
arranque aplique las migraciones nuevas, o correrlas a mano:

```bash
dcp exec api php artisan migrate --force
```

## 4. Actualizar la aplicación

```bash
git pull                 # o rsync del código al VPS
dcp build
dcp up -d                # recrea api con la imagen nueva
```

El entrypoint recachea config, rutas y vistas en cada arranque. Como el código
vive dentro de la imagen, **no** hace falta `artisan optimize` a mano.

Para versionar imágenes en vez de pisar `latest`:

```bash
API_TAG=$(date +%Y%m%d-%H%M) dcp build && API_TAG=$(date +%Y%m%d-%H%M) dcp up -d
```

## 5. Backups

Lo hace `docker/backup.sh`, parado en `api/docker/`. Toma la contraseña de
`.env.production`, así que no hay credenciales en la línea de comandos:

```bash
./backup.sh          # dump + tar del volumen storage + rotación + offsite
./backup.sh verify   # restaura el último dump en una base descartable
./backup.sh list     # qué hay guardado
```

En cron, una vez por día:

```cron
15 3 * * * cd /ruta/al/repo/api/docker && ./backup.sh >> /var/log/hotelignite-backup.log 2>&1
```

Guarda en `/var/backups/hotelignite` (`BACKUP_DIR`), 7 diarios y 4 semanales
(los domingos). El dump se valida antes de darlo por bueno: `gzip -t` y el
marcador `Dump completed` al final. Sin eso, un `mysqldump` que falla a mitad
pasa por el pipe a `gzip` sin ruido y el cron guarda un archivo truncado que
sólo se descubre el día que hay que restaurarlo.

**Un backup en el mismo disco no cubre la pérdida del VPS.** Configurá un
destino fuera de la máquina en `.env.production`:

```bash
RCLONE_REMOTE=b2:hotelignite-backups   # o s3:, gdrive:, lo que soporte rclone
# o, si preferís rsync a otra máquina:
BACKUP_RSYNC=usuario@host:/backups/hotelignite
```

Sin ninguno de los dos el script funciona igual, pero avisa en cada corrida.

**Corré `./backup.sh verify` después del primer backup y cada vez que cambie el
esquema.** Restaura el último dump en una base descartable, cuenta las tablas,
muestra los conteos de `accommodations`, `users` y `reservations` y borra la
base al terminar. Un backup que nunca se restauró no es un backup.

Los datos persisten en volúmenes nombrados: `hotelignite-prod_db_data`,
`hotelignite-prod_storage`, `hotelignite-prod_traefik_acme`. `dcp down` **no**
los borra; `dcp down -v` sí — no usar en el VPS.

Si el `storage/` actual tiene archivos que conservar (subidas, claves), copiarlos
al volumen en el primer deploy:

```bash
docker cp ../storage/app/public/. hi-api:/var/www/storage/app/public/
dcp exec api chown -R www-data:www-data /var/www/storage
```

## 6. Operación

```bash
dcp ps                       # estado + healthchecks
dcp logs -f api              # nginx, php-fpm y Laravel salen todos por acá
dcp exec api php artisan tinker
dcp exec api php artisan cache:clear
dcp restart api
dcp down                     # baja todo, conserva volúmenes
```

## 7. Cosas que a propósito **no** están

Se sacaron del stack porque hoy la app no las usa. Si alguna hace falta:

- **Dashboard de Traefik** — apagado con `--api.dashboard=false`. Para
  encenderlo: volver a poner el flag en `true`, agregar el router
  `Host(...)` con `service=api@internal` y protegerlo con un middleware
  `basicauth`.

## 8. Detalles que conviene saber

- **Un contenedor, cuatro procesos**: nginx, php-fpm, el worker de colas y el
  scheduler, todos bajo `supervisord`. Si alguno queda en FATAL, un
  `eventlistener` baja supervisord y docker reinicia el contenedor. php-fpm
  escucha sólo en `127.0.0.1:9000`.
- **Colas sobre MySQL** (`QUEUE_CONNECTION=database`, tablas `jobs` y
  `failed_jobs`). No van al Redis del stack a propósito: su política es
  `allkeys-lru` y evictaría jobs pendientes. El worker recicla cada hora
  (`--max-time=3600`) para acotar fugas de memoria; supervisord lo relevanta.
- **Scheduler**: un loop que llama `schedule:run` cada 60s, en vez de sumarle
  cron al contenedor. Hoy corre la purga de tokens de Sanctum vencidos y la de
  jobs fallidos de más de una semana (`app/Console/Kernel.php`).
- **Redes**: `hotelignite_web` (Traefik ↔ api) y `hotelignite_internal`
  (api ↔ MySQL, marcada `internal: true`, sin salida a internet). MySQL no
  publica puertos al host: para un cliente SQL, túnel SSH o `dcp exec db mysql -u...`.
- **HTTPS**: Traefik redirige 80 → 443, agrega HSTS, `X-Content-Type-Options`,
  `frameDeny`, `Referrer-Policy` y compresión. `TrustProxies` confía sólo en
  rangos privados (la red de Docker) para que Laravel genere URLs `https` detrás
  del proxy sin que un `X-Forwarded-For` del cliente evada los throttles por IP;
  ajustable con `TRUSTED_PROXIES`.
- **`TrustHosts` activo**: la app sólo acepta el host de `APP_URL` y sus
  subdominios. Traefik enruta por `Host` pero no borra un `X-Forwarded-Host`
  entrante, así que sin esto un atacante conseguía que los links generados
  (mails, `url()`, `route()`) apuntaran a su dominio. Para sumar otro host —un
  staging, un alias— está `TRUSTED_HOSTS`, coma-separado. Es no-op en `local`.
- **Errores siempre en JSON**: `Handler::render()` fuerza `Accept:
  application/json` para todo lo que cuelga de `api/*` y `pms/*`. Un integrador
  que omita el header recibe un error parseable y no la página HTML de Symfony.
- **`/healthz`**: lo responde nginx sin tocar PHP; es el healthcheck del
  contenedor.
- **PHP 8.4 + Laravel 13**: desde el upgrade del 2026-08-30 el arranque ya
  no escupe deprecations. Si volvieran a aparecer, revisá `docker/php.ini`.
- **`composer.lock`**: `nette/schema` y `nette/utils` se subieron a 1.3.6 y
  4.1.5 porque las versiones anteriores declaran `php <8.4` y hacían fallar el
  `composer install` de la imagen. Ambas siguen soportando 8.3, así que el
  entorno de desarrollo no cambia.
- **Nombre de proyecto**: `hotelignite-prod`, distinto del de desarrollo
  (`hotelignite`), para que un `up` de producción no pise contenedores locales.
- **Frontends** (`crm/`, `clients/`, `electron/`, `pms/`): no están en este
  compose. Se agregan como servicios con sus propias labels de Traefik
  (`Host(...)` + `loadbalancer.server.port`) sobre la red `web`.

## 9. Problemas frecuentes

| Síntoma | Causa / solución |
|---|---|
| Traefik no emite certificado | DNS todavía no propagado, o el puerto 443 ocupado. `dcp logs traefik` |
| `ERROR: APP_KEY vacío` | Falta `APP_KEY` en `.env.production` (ver §2) |
| `MySQL no respondió en 60s` | El contenedor `db` no arranca: `dcp logs db`. Contraseña cambiada con el volumen ya inicializado → borrar el volumen o resetear la clave |
| 502 desde Traefik | El contenedor `api` está reiniciando: `dcp logs api` |
| Cambios de código que no se ven | Falta `dcp build` (opcache con `validate_timestamps=0` y código dentro de la imagen) |
| `413 Request Entity Too Large` | Subir `client_max_body_size` en `docker/nginx.conf` y `upload_max_filesize`/`post_max_size` en `docker/php.ini` |
