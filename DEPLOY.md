# Deploy en el VPS — API HotelIgnite

**En el VPS va sólo la API.** Los frontends (`crm/`, `clients/`, `pms/` y la web
de TuriNorte) se despliegan en **Vercel**: no hay servicios de Next.js en este
stack ni labels de Traefik para ellos. Lo único que necesitan de acá es que
`https://api.tudominio.com` sea público y esté en `CORS_ALLOWED_ORIGINS`.

## Dos stacks, no uno

| Stack | Archivo | Qué corre | Alcance |
|---|---|---|---|
| **Borde** | `docker/docker-compose.traefik.yml` | Traefik (TLS + Let's Encrypt) | **Compartido por todo el VPS** |
| **API** | `docker/docker-compose.prod.yml` | MySQL 8, Redis, nginx + php-fpm 8.4 | Sólo HotelIgnite |

Están separados porque **los puertos 80 y 443 son únicos en la máquina**. Con el
borde adentro del compose de la API, esa app pasaría a ser la dueña implícita del
ingreso de todo el VPS: un `down` para actualizarla dejaría sin TLS a cualquier
otro proyecto alojado ahí, y un `down -v` mal tipeado se llevaría `acme.json`
—con Let's Encrypt limitando a 5 emisiones por dominio por semana, eso no se
recupera en el momento—.

Los dos se comunican por una red de Docker llamada `edge`, creada a mano y
declarada `external` en ambos. Para publicar otro proyecto en este VPS no se
toca nada de acá: se lo cuelga de `edge` y se le ponen sus propias labels de
router.

**Los middlewares de HotelIgnite viven en el router de la API, no en el
entrypoint del borde.** En el entrypoint alcanzarían a todos los proyectos del
VPS, y un rate limit calibrado para una API JSON (120 req/min) estrangularía a
un sitio con imágenes. Es la consecuencia de compartir el borde y hay que
respetarla al agregar el proyecto siguiente.

A diferencia de `docker-compose.yml` (desarrollo, en la raíz del monorepo), acá
**no hay bind mounts del código**: la imagen se construye con el código y el
`vendor/` adentro, y opcache corre con `validate_timestamps=0`. Para cambiar
código hay que reconstruir la imagen.

Todo lo de deploy vive en `api/docker/`:

| Archivo | Qué es |
|---|---|
| `docker/docker-compose.traefik.yml` | Borde compartido del VPS (proyecto `edge`) |
| `docker/.env.traefik.example` | Sus variables — copiar a `docker/.env.traefik` |
| `docker/docker-compose.prod.yml` | Stack de la API (proyecto `hotelignite-prod`) |
| `docker/.env.production.example` | Plantilla de variables — copiar a `docker/.env.production` |
| `docker/Dockerfile` | Imagen única: php-fpm 8.4 + nginx + supervisord |
| `docker/entrypoint.sh` | Espera MySQL, migra (opcional) y cachea config/rutas/vistas |
| `docker/nginx.conf` | nginx de producción (gzip, cache, CSP, `/healthz`) |
| `docker/php.ini` | opcache, límites, errores al log |
| `docker/php-fpm.conf` | Pool fpm, slowlog |
| `docker/supervisord.conf` | php-fpm, nginx, worker de colas y scheduler |
| `docker/backup.sh` | Backup de la base y de `storage`, con `verify` |
| `.dockerignore` | Queda en `api/` porque ése es la raíz del contexto de build |

## 1. Requisitos en el VPS

- Docker Engine + plugin `compose` v2.
- Puertos **80** y **443** libres. Los toma **Traefik**, y a partir de ahí son
  suyos: si ya corre un nginx o un Apache en el host, apagalo — no lo conviertas
  en un segundo borde. Todo lo que se publique en este VPS entra por Traefik.
- DNS apuntando al VPS **antes** de levantar (Let's Encrypt valida por TLS-ALPN):
  `api.tudominio.com` → IP del VPS.
- La red compartida y el archivo de certificados, una sola vez:
  ```bash
  docker network create edge
  cd api/docker && touch acme.json && chmod 600 acme.json
  ```
  **Los dos pasos importan y fallan callados.** `acme.json` es un bind mount de
  un *archivo*: si no existe, Docker crea un **directorio** con ese nombre; y
  con permisos distintos de `600` Traefik descarta el resolver
  (`permissions 755 for /letsencrypt/acme.json are too open`). En los dos casos
  el contenedor queda `Up` y sano, pero **todos los sitios sirven el certificado
  self-signed de Traefik** y el browser muestra advertencia.

## 2. Configuración

Dos archivos, uno por stack:

```bash
cd api/docker
cp .env.traefik.example    .env.traefik        # ACME_EMAIL, DOMAIN, dashboard
cp .env.production.example .env.production
chmod 600 .env.traefik .env.production         # los dos llevan credenciales
$EDITOR .env.traefik .env.production
```

En `.env.traefik`: `ACME_EMAIL`, `DOMAIN` (el dashboard queda en
`traefik.<DOMAIN>`) y `DASHBOARD_USERS`, en formato htpasswd:

```bash
docker run --rm httpd:alpine htpasswd -nbB admin 'una-password-larga'
```

**El hash va entre comillas simples.** Lleva `$`, y compose interpola variables
también dentro del `.env`: sin escapar, `admin:$2y$05$Xk9p...` le llega a
Traefik como `admin:$2y$05` —el resto se toma por una variable inexistente— y la
password no anda sin que nada lo reporte. Verificado con compose v2.29.7.
Duplicar cada `$` a `$$` también funciona; las comillas son menos frágiles.

Mínimo a completar en `.env.production`:

- `API_DOMAIN` (el `ACME_EMAIL` ahora es del borde, va en `.env.traefik`)
- `DB_PASSWORD`, `DB_ROOT_PASSWORD` (largos y distintos)
- `APP_URL=https://api.tudominio.com`, `APP_DEBUG=false`
- `CORS_ALLOWED_ORIGINS` con **los dominios de Vercel** de los tres paneles.
  Ojo con esto al desplegar: cada panel tiene su dominio de producción y además
  una URL de preview por cada deploy. Las previews no van en la lista —
  autorizarlas es dejar que cualquier branch hable con la API de producción. Si
  hace falta una, usá `CORS_ALLOWED_ORIGIN_PATTERNS` con una regex acotada.
- `APP_KEY`:
  ```bash
  docker compose --env-file .env.production -f docker-compose.prod.yml \
      run --rm --no-deps api php artisan key:generate --show
  ```
  (el entrypoint deja pasar de largo los comandos puntuales: no espera la base
  ni exige `APP_KEY` salvo cuando arranca el servicio)

  La salida tiene que ser **una sola línea que empieza con `base64:`**. Hasta el
  2026-09-22 el entrypoint logueaba a stdout y `APP_KEY=$(... --show)` se
  llevaba también `[entrypoint] comando puntual: ...`; la key quedaba inválida y
  el contenedor arrancaba igual, healthcheck en verde, hasta que algo intentaba
  desencriptar. Ya está corregido —los logs del entrypoint van a stderr— pero si
  ves algo más que la línea `base64:`, no la pegues.

`DB_HOST` y `DB_PORT` los fija el compose apuntando al servicio `db`: **no** los
pongas en el `.env.production`.

### `SANCTUM_STATEFUL_DOMAINS` no hace falta

Está en la plantilla por herencia y no molesta, pero **no interviene en este
flujo**. Los paneles no son SPAs con sesión por cookie contra la API: llaman a
`/api/admin/v1` **desde el servidor de Next** con un Bearer token, y la cookie
httpOnly (`hi_crm_token`, `hi_client_token`, `hi_token`) es de cada app en su
propio dominio de Vercel, no de la API. Sanctum stateful es para el otro modelo.

Consecuencia práctica de que las llamadas sean server-side, y conviene tenerla
presente: **la API nunca ve la IP del usuario final**, ve la de la función de
Vercel. Los throttles por IP de Laravel quedan gobernados en la práctica por la
otra mitad de su clave (el email en `throttle:login`, el user id en
`throttle:api`). Por eso el CRM tiene su propio freno de intentos de login
(`crm/lib/auth/rate-limit.ts`), que es el único lugar donde la IP real es
visible.

Para no repetir flags, parado en `api/docker/`, un alias por stack:

```bash
alias dce='docker compose --env-file .env.traefik    -f docker-compose.traefik.yml'
alias dcp='docker compose --env-file .env.production -f docker-compose.prod.yml'
```

## 3. Primer deploy

El orden importa: la red primero, después el borde, después la API. Si la API
arranca sin la red `edge`, el compose falla con un error de red externa
inexistente — no queda a medias.

```bash
docker network create edge     # una sola vez en el VPS
dce up -d
dce logs -f traefik            # dejalo mirando la emisión del certificado
```

```bash
dcp build
dcp up -d
dcp ps
```

El contexto de build es `api/` (el compose usa `context: ..`), así que el
`.dockerignore` que manda es `api/.dockerignore`.

### Comprobá que el contenedor **arranque**, no sólo que buildee

`dcp build` verde no dice nada sobre si el contenedor levanta. El 2026-08-31 la
imagen buildeaba perfecto y php-fpm moría en el arranque por un comentario con
`#` en `php-fpm.conf` (el parser INI de PHP sólo entiende `;`): supervisord lo
daba por FATAL, bajaba el contenedor y desde afuera se veía un **502 de
Traefik**, con la causa escondida en el log entre el arranque de los otros
procesos.

Dos chequeos, diez segundos:

```bash
dcp logs api | grep RUNNING    # php-fpm, nginx, queue-worker y scheduler, los cuatro
curl -sI https://api.tudominio.com/api/v1/accommodations | head -1   # 401 = vivo
```

### Cargar la base

Desde el 2026-08-31 `php artisan migrate` **sí corre desde cero** (75
migraciones, 0 pendientes, las mismas 28 foreign keys que la base de
desarrollo). Pero eso te da el **esquema, no los datos**, así que para el primer
deploy el camino sigue siendo importar un dump del entorno actual — incluida la
tabla `migrations`, así las migraciones nuevas se aplican encima:

`backup.sh` **no sirve para este paso**: exige `.env.production` y el
contenedor `hi-db`, y en la máquina de desarrollo no hay ninguno de los dos
(muere con `ERROR: falta .env.production`). Es el script del backup diario *del
VPS*. Para sacar el dump de desarrollo, el contenedor se llama `db`:

```bash
# en la máquina de desarrollo, desde la raíz del monorepo
docker exec db sh -c 'mysqldump -uroot -p123456789 \
    --single-transaction --routines --triggers --events \
    --default-character-set=utf8mb4 hotelignite' | gzip > db-dev.sql.gz

gzip -t db-dev.sql.gz && zcat db-dev.sql.gz | tail -1   # "Dump completed" = íntegro
```

```bash
# copiar el .sql.gz al VPS y restaurar (el zcat ocurre en el host)
zcat db-dev.sql.gz | dcp exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

**Desarrollo corre MySQL 8.4 y producción 8.0**, así que el dump cruza de una
serie mayor a una menor. Verificado el 2026-09-22: el dump de desarrollo importa
limpio en `mysql:8.0.43` —75 tablas, 28 foreign keys, 80 filas en `migrations`,
77 alojamientos, sin un solo warning—. No hace falta alinear las versiones.

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

El borde no se toca: `dce` se corre una vez y queda. Actualizar la API **no**
interrumpe a los otros proyectos del VPS — que es exactamente para lo que se
separaron los dos stacks.

El entrypoint recachea config, rutas y vistas en cada arranque. Como el código
vive dentro de la imagen, **no** hace falta `artisan optimize` a mano.

Para versionar imágenes en vez de pisar `latest`:

```bash
API_TAG=$(date +%Y%m%d-%H%M) dcp build && API_TAG=$(date +%Y%m%d-%H%M) dcp up -d
```

## 5. Backups

Lo hace `docker/backup.sh`, parado en `api/docker/`. Toma la contraseña del archivo de entorno,
así que no hay credenciales en la línea de comandos.

**Qué archivo de entorno lee:** prueba `.env.production` y, si no está, `.env`; `ENV_FILE=` fuerza
uno. La doble opción no es cosmética: el VPS corre `docker/docker-compose.yml` con `env_file: .env`,
y mientras el script exigió `.env.production` abortaba en la primera línea — por eso ese servidor
estuvo sin un solo backup desde que se levantó.

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

- **Restringir el dashboard por IP** — hoy está publicado en
  `traefik.<DOMAIN>` detrás de basicauth y TLS, que es aceptable, pero expone el
  mapa de routers y servicios de **todos** los proyectos del VPS. Con IP fija,
  sumale un `ipallowlist` y encadenalo al router:
  ```yaml
  - traefik.http.middlewares.dashboard-ip.ipallowlist.sourcerange=TU.IP/32
  - traefik.http.routers.traefik-dashboard.middlewares=dashboard-auth,dashboard-ip
  ```
- **TLS por defecto en el entrypoint** — con
  `--entryPoints.websecure.http.tls.certresolver=le` cada router nuevo obtiene
  certificado sin declararlo. No está puesto porque un router existente con
  `tls=true` y sin resolver empezaría a pedir certificado real y fallaría si su
  DNS no apunta al VPS — y Let's Encrypt limita a 5 emisiones por dominio por
  semana.
- **Los frontends** (`crm/`, `clients/`, `pms/`, TuriNorte) — van a **Vercel**,
  no a este VPS. Lo único que necesitan de acá es que la API sea pública por
  https y que sus dominios estén en `CORS_ALLOWED_ORIGINS`. Si algún día alguno
  se mudara al VPS, sería un compose propio colgado de `edge` con sus labels de
  router; no un servicio de éste.

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
- **Redes**: `edge` (Traefik ↔ api, compartida con el resto del VPS y creada a
  mano) y `hotelignite_internal` (api ↔ MySQL ↔ Redis, marcada `internal: true`,
  sin salida a internet). MySQL no publica puertos al host: para un cliente SQL,
  túnel SSH o `dcp exec db mysql -u...`.

  Que `edge` sea compartida tiene una consecuencia que conviene saber: **los
  contenedores colgados de ella se ven entre sí**. Es la razón por la que la
  base y Redis están en la red interna y no en `edge` — ahí serían alcanzables
  desde cualquier otro proyecto del VPS. Al sumar un proyecto nuevo, poné en
  `edge` sólo su contenedor web.
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
- **Nombres de proyecto**: `edge` para el borde y `hotelignite-prod` para la API,
  los dos distintos del de desarrollo (`hotelignite`), para que un `up` de
  producción no pise contenedores locales.

## 9. Problemas frecuentes

| Síntoma | Causa / solución |
|---|---|
| `network edge declared as external, but could not be found` | Falta `docker network create edge`, o el borde nunca se levantó (§3) |
| Traefik no emite certificado | DNS todavía no propagado, o el puerto 443 ocupado. `dce logs traefik` |
| El browser muestra certificado inválido en **todos** los sitios | El resolver ACME se descartó al arrancar: `acme.json` quedó como directorio, o sin permisos `600`. `dce logs traefik \| grep -i acme` (§1) |
| El dashboard rechaza la password correcta | El `$` del hash se comió la interpolación de compose. `DASHBOARD_USERS` va entre comillas simples en `.env.traefik` (§2) |
| **404** en un dominio que debería andar | Traefik está vivo pero no encontró router: el contenedor no está en la red `edge`, le falta `traefik.enable=true`, o el `Host()` no coincide. `dce logs traefik \| grep -i error` |
| `ERROR: APP_KEY vacío` | Falta `APP_KEY` en `.env.production` (ver §2) |
| `MySQL no respondió en 60s` | El contenedor `db` no arranca: `dcp logs db`. Contraseña cambiada con el volumen ya inicializado → borrar el volumen o resetear la clave |
| **502** desde Traefik | El contenedor `api` está reiniciando: `dcp logs api`. Mirá primero si algún proceso de supervisord quedó en FATAL (§3) |
| 429 en requests normales | El rate limit del borde (120/min por IP) está en el router `hi-api`. Si te pega a vos, es que las llamadas llegan todas desde una IP — ver la nota de Vercel en §2 |
| Cambios de código que no se ven | Falta `dcp build` (opcache con `validate_timestamps=0` y código dentro de la imagen) |
| `413 Request Entity Too Large` | Subir `client_max_body_size` en `docker/nginx.conf` y `upload_max_filesize`/`post_max_size` en `docker/php.ini` |
