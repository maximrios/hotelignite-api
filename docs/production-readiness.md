# Producción — qué falta y qué riesgos quedan abiertos

Fecha del análisis: 2026-08-30. Alcance: `api/` (Laravel 9.52 / PHP 8.4) con el
stack de `api/docker/` (Traefik → nginx+php-fpm → MySQL 8) descrito en `DEPLOY.md`.

Este documento **no repite** lo ya planificado en `docs/security-hardening-plan.md`
ni en `docs/api-readiness.md`: revisa el estado *real del código y del stack de
deploy* y lista lo que todavía impide salir a internet con tranquilidad.

---

## Estado

**Hecho el 2026-08-30:** puntos 1 y 2 cerrados. El scaffolding de Breeze se
retiró y la API está en **Laravel 13.29.0 sobre PHP 8.4**, con la suite en
**72/72 verde** y `composer audit` limpio. El detalle del upgrade está en
`docs/laravel-upgrade-log.md`. También el punto 3 (rate limit en el borde, Redis
para el limiter, y el agujero de `X-Forwarded-For` que apareció al investigarlo).

**Hecho el 2026-08-31:** puntos 4, 5, 7, 8, 9, 10, 14 y 16, más un bloqueante
que no estaba en la lista porque nadie había arrancado la imagen (ver abajo).
Suite en **73/73 verde**.

### El bloqueante que faltaba: la imagen de producción no arrancaba

`docker/php-fpm.conf` tenía el bloque de comentarios de `pm.max_children`
escrito con `#`. Ese archivo lo lee el parser INI de PHP, que sólo entiende `;`:
tomaba cada línea como una entrada sin valor y php-fpm abortaba con
`value is NULL for a ZEND_INI_PARSER_ENTRY`. supervisord lo reintentaba tres
veces, lo daba por FATAL y el `eventlistener` bajaba el contenedor entero.

O sea: **el primer `dcp up -d` en el VPS habría dado 502 desde Traefik**, con el
contenedor en loop de reinicio y el error escondido en `dcp logs api` entre el
arranque de los otros procesos. Se encontró corriendo la imagen contra la base
de desarrollo, no leyéndola.

La lección operativa: `dcp build` verde no dice nada sobre si el contenedor
levanta. Antes de tocar el VPS conviene correr la imagen local contra la base de
dev, que es lo que se hizo acá y toma dos minutos:

```bash
docker run -d --name hi-preflight --network hotelignite -p 8099:80 \
    -e APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)" \
    -e APP_ENV=production -e APP_DEBUG=false -e APP_URL=http://api.hotelignite.local \
    -e DB_HOST=db -e DB_DATABASE=hotelignite -e DB_USERNAME=root -e DB_PASSWORD=123456789 \
    hotelignite/api:preflight
docker logs hi-preflight            # los cuatro programas en RUNNING
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8099/healthz
```

**Bloqueantes que quedan:** ninguno de los originales. Lo que sigue abierto es
el punto 6 (tenencia de recursos hijos), que es lo que hay que cerrar **antes de
abrir a clientes B2B reales**, y el 11 (CI).

## Resumen

El hardening de aplicación (bloques A–E) está hecho y se nota: tenencia por
`account_id` con Policies, throttles diferenciados por superficie, CORS por env,
API keys de client con tier, MySQL sin puertos publicados, TLS con HSTS. Lo que
falta no es más de lo mismo: es **superficie residual que quedó viva**,
**infraestructura de defensa que no existe** y **un framework sin soporte**.

| # | Hallazgo | Severidad |
|---|---|---|
| 0 | ~~La imagen de producción no arrancaba (`php-fpm.conf` con `#`)~~ — **RESUELTO 31/08** | ✅ |
| 1 | ~~Scaffolding web de Breeze vivo~~ — **RESUELTO 30/08** | ✅ |
| 2 | ~~Laravel 9 EOL~~ — **RESUELTO 30/08: en Laravel 13.29 + PHP 8.4** | ✅ |
| 3 | ~~Rate limit sólo en la app~~ — **RESUELTO 30/08**; falta el CDN | ✅ |
| 4 | ~~Sin backups~~ — **RESUELTO 31/08: `docker/backup.sh`, con `verify`** | ✅ |
| 5 | ~~`migrate` no corre desde cero~~ — **RESUELTO 31/08: 75 migraciones, 28 FKs = dev** | ✅ |
| 6 | Tenencia de recursos hijos (B8) sin terminar → fuga entre hoteles | **Alto** |
| 7 | ~~Falta habilitar `TrustHosts`~~ — **RESUELTO 31/08** | ✅ |
| 8 | ~~`Handler` sin `render()`~~ — **RESUELTO 31/08**; falta el monitoreo (Sentry) | ⚠️ |
| 9 | ~~`QUEUE_CONNECTION=sync`~~ — **RESUELTO 31/08: `database` + worker** | ✅ |
| 10 | ~~`V1\AccountResource` devuelve `accounts.token`~~ — **BORRADO 31/08** | ✅ |
| 11 | Suite verde (73/73); **falta el CI** que la corra | **Alto** |
| 12 | ~~Sin CSP a nivel app~~ — **RESUELTO 31/08 en `docker/nginx.conf`** | ✅ |
| 13 | `docker.sock` montado en Traefik | Medio |
| 14 | ~~Tokens Sanctum vencidos nunca se purgan~~ — **RESUELTO 31/08: scheduler** | ✅ |
| 15 | `url` de imágenes acepta cualquier esquema válido para `filter_var` | Medio |
| 16 | Residuos — **fuera de la imagen 31/08**; quedan en el árbol local | ⚠️ |

---

## Bloqueantes

### 1. El scaffolding web de Breeze está vivo y expuesto

`routes/web.php` carga `routes/auth.php` completo y el `RouteServiceProvider` lo
registra. En producción, sobre `api.hotelignite.com`, quedan públicas:

- `POST /register` → `RegisteredUserController::store` crea una fila en `users`.
  **El grupo `web` no tiene `throttle`** (ver `app/Http/Kernel.php`): es escritura
  no autenticada e ilimitada en la base de producción.
- `POST /login` (web, sesión) — también sin throttle. El `throttle:login` de
  5/min por email+IP protege únicamente `POST /api/auth/login`, así que la fuerza
  bruta tiene una puerta abierta al lado de la puerta blindada.
- `POST /forgot-password`, `POST /email/verification-notification` → envío de
  mail disparado por anónimos desde tu dominio (reputación SMTP).
- `GET /redirect` — ruta de debug de Passport que arma un `state` en sesión y
  redirige a `http://localhost/oauth/authorize` con
  `redirect_uri=http://third-party-app.com/callback`.
- `GET /dashboard`, `GET /profile`, `GET /clients`, `GET /` (vista `welcome`).

**Mitigante que evita que sea catastrófico:** el usuario auto-registrado nace con
`user_type = NULL`, y `AccommodationPolicy` / `EnsurePlatform` / `EnsureClientUser`
lo rechazan todo (`isAccount()`, `isClient()`, `isPlatform()` dan `false`). No ve
datos de nadie. Pero sigue siendo escritura anónima, envío de mail anónimo y
fuerza bruta sin freno.

Ningún frontend del monorepo consume estas rutas (los paneles usan
`/api/admin/v1` con Bearer).

**Acción:** borrar `routes/auth.php`, vaciar `routes/web.php`, eliminar
`app/Http/Controllers/Auth/`, `ProfileController`, `resources/views/auth/`,
`dashboard.blade.php`, `welcome.blade.php`, y sacar `laravel/breeze` de
`composer.json`. Si querés conservar algo, que sea un `GET /` que devuelva 404 o
un JSON con la versión de la API.

### 2. Laravel 9 está fuera de soporte

`laravel/framework v9.52.16`. Las correcciones de seguridad de la rama 9
terminaron el **6 de febrero de 2024**. Cualquier CVE del framework posterior a
esa fecha no tiene parche para vos. Además `composer.json` declara `php: ^8.0.2`
mientras la imagen corre PHP 8.4, lo que genera un chorro de `E_DEPRECATED` desde
`vendor/` (hoy silenciados en `php.ini`).

Es el único riesgo del que no te podés defender con configuración.

#### Decisión (2026-08-30): upgrade in situ, no rewrite

Se evaluó empezar un proyecto Laravel 13 limpio y portar todo. **Se descartó.**

*La evidencia pesa más que el argumento:* `newapi/` es exactamente ese
experimento —"empezamos limpio en Laravel 11"—, creado en enero 2025 y con última
actividad en diciembre 2025. Su contenido hoy: **1 modelo** (el `User.php` del
instalador), **1 controlador** (el `Controller.php` base abstracto) y **8
migraciones**, todas scaffolding más Passport. Cero código de dominio en 19
meses. `apix/` es un tercer intento del mismo movimiento. Mientras tanto `api/`
llegó a **384 archivos / 16.340 líneas** con toda la tenencia, los clients B2B,
invitaciones y legajos.

*El rewrite no arregla lo que da ganas de hacerlo:* las verrugas que molestan
—el `total` de paginación que miente, `AccommodationRespository` con el typo, el
CRUD duplicado de `Account`, `cities.state_id int NOT NULL DEFAULT 0`— la mitad
están **en el esquema**, y el esquema se conserva porque la base de producción es
la misma. Un Laravel 13 nuevo hablándole a esa base hereda todas las verrugas de
datos y ninguna de las soluciones ya escritas.

*El costo real de portar no son los controladores:* es re-derivar las decisiones
documentadas en `CLAUDE.md` (por qué cross-tenant devuelve 404 y no 403, por qué
`BookingRepository::store` exige visibilidad y no update, por qué `states` se
filtra por país). Cada omisión al portarlas es un bug de aislamiento entre
hoteles. Y durante todo el rewrite se mantienen dos bases de código, porque
`crm/`, `clients/`, `pms/` y `app/` consumen `api/` hoy.

*Y este upgrade es de los fáciles.* El predictor número uno de dolor son las
dependencias de terceros, y no hay ninguna: `require` es `php`, `guzzle`,
`framework`, `sanctum`, `tinker`. El escaneo de fricciones conocidas 9→13 da casi
todo en cero — sin `$dates`, sin custom casts, sin `->change()` en migraciones,
sin paths viejos de `lang/`. Aparece **un** `assertSoftDeleted` y **un** Mailable
con `build()`. Breeze se va igual por el punto 1.

Dato que suele frenar y es infundado: **el skeleton slim de Laravel 11 es
opcional.** No hay que mover `app/Http/Kernel.php` a `bootstrap/app.php`; la
estructura vieja sigue funcionando en 11, 12 y 13.

**Ruta:** un salto por PR — 9→10 (el más grande: PHP 8.1+, `$routeMiddleware` →
`$middlewareAliases`), 10→11 (Sanctum 3→4), 11→12 y 12→13 (casi triviales; 13
pide PHP ^8.3 y la imagen ya corre 8.4). [Laravel Shift](https://laravelshift.com)
hace lo mecánico por ~US$35 el salto.

**Prerequisito innegociable:** CI verde con la suite de tenencia. Ver
`docs/migrations-and-ci-plan.md`. Sin eso, el upgrade es a ciegas.

### 3. Rate limiting — **RESUELTO 30/08**

Lo que había: cuatro limiters de Laravel bien diseñados (`api` 60/min, `login`
5/min, `client` por tier, `invitations`). El problema era **dónde y sobre qué
corrían**, más un agujero que no estaba en el diagnóstico original.

#### El agujero: el throttle por IP se evadía con un header

`TrustProxies::$proxies` estaba en `'*'`. Con eso Laravel confía en toda la
cadena de `X-Forwarded-For` y `$request->ip()` devuelve la entrada **de más a la
izquierda** — la que escribe el cliente. Rotando ese header se hacían 10 intentos
de login seguidos sin un solo 429: la protección anti-fuerza-bruta anulada por
una línea de curl. Verificado empíricamente.

**Matiz importante, verificado y no asumido:** en producción esto **no** era
explotable. Traefik, por defecto, *reemplaza* los `X-Forwarded-*` de clientes no
confiables en vez de apendearlos (probado con un Traefik v3.3 real: un
`X-Forwarded-For: 203.0.113.99` llegó al backend como la IP real). La evasión
funcionaba en el stack de desarrollo, donde nginx pasaba el header del cliente
tal cual.

Pero esa protección era **incidental**: dependía del default del borde, no de la
app. Y se rompe justo cuando se agrega el CDN del punto siguiente — con
Cloudflare delante hay que sumar sus rangos a `forwardedHeaders.trustedIPs` de
Traefik, y ahí Traefik **sí** preserva la cadena entrante.

**Hecho:**
- `TrustProxies` confía sólo en rangos privados (la red de Docker), configurable
  por `TRUSTED_PROXIES`. `127.0.0.1` queda afuera a propósito.
- El nginx de desarrollo reescribe `X-Forwarded-For` con la IP real, replicando
  lo que hace Traefik: dev dejó de mentir sobre el comportamiento de producción.
- `tests/Feature/RateLimitSpoofingTest.php` fija la propiedad. Se comprobó que
  falla con la configuración vieja y pasa con la nueva.

#### Rate limit en el borde

`docker-compose.prod.yml` suma dos middlewares al entrypoint `websecure`, que
corren **antes** de php-fpm:

- `hi-ratelimit`: `RATELIMIT_AVERAGE` (120) por minuto con burst
  `RATELIMIT_BURST` (60), por IP de origen.
- `hi-inflight`: `INFLIGHT_AMOUNT` (15) requests simultáneas por IP.

`sourceCriterion` queda en su default —la IP del socket, no `X-Forwarded-For`—
así que ningún header los evade. `INFLIGHT_AMOUNT` se mantiene por debajo de
`pm.max_children` (20) para que una sola IP no pueda copar el pool de workers.

#### Redis para el limiter

`CACHE_DRIVER=file` hacía que cada chequeo del limiter fuera lectura + escritura
+ lock en disco: bajo un flood, el limiter se volvía parte del problema. Se
agregó un servicio `redis` en la red `internal` y `CACHE_DRIVER=redis`, más la
extensión `phpredis` en las imágenes de producción **y** de desarrollo.
Verificado: el `RateLimiter` de Laravel bloquea correctamente sobre Redis.

La política es `allkeys-lru`, correcta para cache puro. **Si las colas pasan a
Redis (§9), no reusar esta instancia**: evictaría jobs pendientes.

#### Ventana de los workers

`request_terminate_timeout` bajó de 120s a 60s, alineado con
`fastcgi_read_timeout` de nginx. Con 20 workers, una request que retiene uno dos
minutos es munición gratis. `pm.max_children` se dejó en 20 pero quedó
documentada la fórmula para dimensionarlo contra la RAM real del VPS.

#### Lo que sigue pendiente acá

**Cloudflare (u otro CDN/WAF) delante del VPS.** Es lo único que resuelve un
ataque volumétrico L3/L4; lo de arriba cubre el L7. Al ponerlo hay que hacer las
dos cosas juntas: sumar los rangos de CF a `TRUSTED_PROXIES` y a
`--entrypoints.websecure.forwardedHeaders.trustedIPs`. Si se hace sólo lo
segundo, vuelve el agujero del `X-Forwarded-For`.

### 4. Backups — **RESUELTO 31/08**

Lo hace `docker/backup.sh` (documentado en `DEPLOY.md` §5): `mysqldump` con
`--single-transaction`, tar del volumen `storage`, rotación 7 diarios + 4
semanales y sincronización opcional a un destino externo.

Dos detalles que valen más que el script en sí:

- **El dump se valida antes de darlo por bueno** (`gzip -t` + el marcador
  `Dump completed`). Sin eso, un `mysqldump` que falla a mitad pasa por el pipe
  a `gzip` sin ruido: el cron guarda un `.sql.gz` truncado y el problema aparece
  el día de la restauración.
- **`./backup.sh verify` restaura de verdad** —en una base descartable, contando
  tablas y filas de control— y la borra al terminar. Probado el 31/08 contra la
  base de desarrollo: 75 tablas, 77 alojamientos, 9 usuarios, 1356 reservas.

Falta una sola cosa, y depende del VPS: definir `RCLONE_REMOTE` o
`BACKUP_RSYNC` en `.env.production`. Sin destino externo el backup queda en el
mismo disco que la base, y el script lo avisa en cada corrida.

<details><summary>Diagnóstico original</summary>


`docker-compose.prod.yml` define `db_data`, `storage` y `traefik_acme` como
volúmenes, y no hay nada que los respalde. Un `docker compose down -v` mal
tipeado, o un disco del VPS, se lleva la base y los legajos de documentos.

**Acción:** `mysqldump` diario a un destino **fuera del VPS** (S3/B2/rsync a otra
máquina), más un `tar` del volumen `storage`. Retención de al menos 7 diarios y
4 semanales. Y **probar la restauración una vez** — un backup no verificado no es
un backup.

</details>

### 5. Migraciones reproducibles — **RESUELTO 31/08**

Los tres agujeros de `docs/migrations-and-ci-plan.md`, cerrados:

1. Se borró la FK fantasma a `rooms` del snapshot de `reservations` (era un
   error de transcripción: no existe ni en dev ni en producción, y contradecía
   la decisión documentada en `2026_03_25_000006`).
2. `2026_08_31_000002_add_rate_plan_foreign_key_to_reservations_table` repone la
   FK `rate_plan_id → rate_plans`, que se perdía porque la guarda
   `hasColumn()` de `2026_05_21_000001` se saltea el bloque entero.
3. `2026_08_31_000001_create_reservations_status_table` crea el catálogo que
   mapea `App\Models\Status` —ninguna migración lo creaba— y siembra los seis
   estados con `updateOrInsert`.

Verificado contra una base descartable: **75 migraciones, 0 pendientes, 28
foreign keys — las mismas 28 que dev**. Las 22 tablas que faltan son las legacy
muertas que no referencia ningún código.

Las dos migraciones nuevas son idempotentes y se aplicaron sobre dev sin
cambiar un dato (77 alojamientos, 1356 reservas, 6 estados, 28 FKs antes y
después).

<details><summary>Diagnóstico original</summary>


**Diagnosticado a fondo el 2026-08-30** corriendo las migraciones contra una base
descartable. El detalle completo y el plan están en
`docs/migrations-and-ci-plan.md`; el resumen:

Falla en la migración **15 de 73**. Pero la causa es más chica de lo que parecía:
el `CREATE TABLE` crudo de `2023_05_21_000015_create_reservations_table` incluye
una `CONSTRAINT ... FOREIGN KEY (room_id) REFERENCES rooms(id)` **que no existe en
la base de dev ni en producción**, y que contradice a la migración
`2026_03_25_000006`, donde está escrito que esa FK deliberadamente no va. Es un
error de transcripción del snapshot.

**Borrando esa línea, las 73 migraciones corren enteras** (verificado: 0
pendientes). Quedan dos agujeros menores: falta la FK
`reservations.rate_plan_id → rate_plans` (única diferencia de FK: dev 28 vs
migrado 27) y falta la tabla `reservations_status`, que `app/Models/Status.php`
mapea. Las otras 22 tablas legacy de dev no las referencia el código: no hay que
migrarlas.

Es medio día de trabajo, no una reescritura del esquema.

</details>

## Altos

### 6. Tenencia de recursos hijos sin terminar (B8)

El patrón está bien resuelto (`BelongsToAccommodation`,
`ChildOfAccommodationPolicy`, `ScopesToAccommodation`, 404 en cross-tenant) y hay
tests (`ChildResourceTenancyTest`). Pero sólo están cubiertos **Room, RoomType,
Reservation y Booking**. Falta el resto del anillo 1 y los anillos 2 y 3.

Traducido: hoy un usuario de la cuenta A puede llegar a leer o escribir recursos
hijos de la cuenta B en los endpoints todavía no cubiertos. **Es el bloqueante
funcional más serio que queda**, y es el que un cliente hotelero nota. Terminar
el checklist de `docs/security-hardening-plan.md` antes de abrir a terceros.

### 7. `TrustHosts` — **RESUELTO 31/08**

Habilitado en `app/Http/Kernel.php`. Acepta el host de `APP_URL` y sus
subdominios, más lo que sume `TRUSTED_HOSTS` (coma-separado). Es no-op en
`local` y bajo tests, así que desarrollo no cambia.

Verificado sobre la imagen de producción con `APP_ENV=production`: un request
con `Host: evil.example.com` devuelve **400**, uno con el host correcto sigue
funcionando.

`TrustProxies` ya había quedado acotado a rangos privados el 30/08 (§3).

<details><summary>Diagnóstico original</summary>


En `app/Http/Kernel.php`, `TrustHosts` está comentado. `TrustProxies` confía en
todos los proxies (`$proxies = '*'`) e incluye `HEADER_X_FORWARDED_HOST`.

Traefik enruta por `Host`, pero no *elimina* un `X-Forwarded-Host` entrante. Un
atacante manda `X-Forwarded-Host: evil.com` y Laravel genera URLs absolutas con
ese host: links de reset de contraseña, links de invitación (`PMS_INVITATION_URL`
está fijado por config, así que ése se salva), y cualquier `url()`/`route()`.

**Acción:** descomentar `TrustHosts` (ya está implementado con
`allSubdomainsOfApplicationUrl()`), y acotar `$proxies` a la subred de Docker o a
los rangos del CDN cuando lo pongas.

</details>

### 8. Errores en JSON — **RESUELTO 31/08 (falta el monitoreo)**

`Handler::render()` fuerza `Accept: application/json` para todo lo que cuelga de
`api/*` y `pms/*` antes de delegar en el handler base. Se fuerza el header en
vez de armar un formato propio para que cada excepción conserve su forma
canónica: 422 con `errors` de validación, 404 de model binding, 403 de Policy.

Verificado sin `Accept`: 404 de ruta, 422 de validación y 401 vuelven todos
`application/json`, y con `APP_DEBUG=false` sin stack trace.

**Sigue faltando el monitoreo** (Sentry o equivalente): si algo explota de
madrugada, la evidencia son `docker logs` rotados a 10 MB × 5.

<details><summary>Diagnóstico original</summary>


`app/Exceptions/Handler.php` no tiene `render()`. Consecuencias en producción:

- Una request sin `Accept: application/json` que falle recibe **HTML** de error,
  no JSON. Los clientes B2B que integren mal van a ver una página en vez de un
  error parseable.
- `ModelNotFoundException`, `AuthorizationException` y validación salen con
  formatos distintos según el `Accept`.
- No hay Sentry ni equivalente: si algo explota a las 3 AM te enterás cuando
  alguien te escribe, y la evidencia son `docker logs` rotados a 10 MB × 5.

**Acción:** un `render()` que fuerce JSON para todo lo que empiece con `api/`, con
forma de error estable (`{message, errors?, code}`), y `sentry/sentry-laravel`
(o Bugsnag / Flare) con `APP_ENV=production`. Existe `json.response` como
middleware, pero sólo se aplica a `client-api.php`.

</details>

### 9. Colas — **RESUELTO 31/08**

`QUEUE_CONNECTION=database` en `.env.production.example` y un
`program:queue-worker` en `docker/supervisord.conf`
(`--tries=3 --backoff=10 --max-time=3600`). Las tablas `jobs` y `failed_jobs` ya
existían. No hizo falta tocar código de aplicación.

**No se usó el Redis del stack a propósito**: su política es `allkeys-lru`, la
correcta para cache puro, y evictaría jobs pendientes. Para mudar las colas a
Redis hace falta una segunda instancia con `noeviction`.

<details><summary>Diagnóstico original</summary>


`InvitationRepository` usa `Mail::to(...)->queue(...)` — el código está bien
escrito, listo para colas. Pero con el driver `sync`, `queue()` **ejecuta en el
acto, dentro del request HTTP**.

Un lote de invitaciones ocupa un worker de php-fpm durante todo el envío SMTP. De
20 workers. Es amplificación de DoS regalada y timeouts para el cliente, y por eso
el limiter de invitaciones tuvo que ponerse alto (un lote es una sola request).

**Acción:** Redis + `QUEUE_CONNECTION=redis` + un `program:queue-worker` en
`supervisord.conf` (`php artisan queue:work --tries=3 --max-time=3600`). No hay
que tocar el código de aplicación.

</details>

### 10. CRUD legacy de `Account` — **BORRADO 31/08**

Se eliminaron controller, repository, interface, form requests, Resource y las
cinco rutas. Con ellos se fueron los otros dos defectos: el `total` de
paginación que ignoraba los filtros y el `destroy` que recibía el id por el
cuerpo de un `DELETE` sin id en la ruta. `/api/admin/v1/accounts` —el que usa el
CRM— quedó intacto; la suite sigue en 73/73.

<details><summary>Diagnóstico original</summary>


`app/Http/Resources/V1/AccountResource.php` devuelve `'token' => $this->token`
(secreto de 40 caracteres) en toda respuesta. Está detrás del middleware
`platform`, así que no es exposición pública, pero es un secreto viajando por la
red y quedando en logs de proxy y en el historial del navegador de quien lo use.

Ya está documentado en `CLAUDE.md` que este CRUD legacy no lo consume ningún
frontend y que el bueno es `/api/admin/v1/accounts`. **Borrarlo antes de salir**
(junto con su `total` de paginación que ignora los filtros y su `destroy` que
recibe el id por body).

</details>

### 11. Sin CI, y la suite de tenencia está roja

Corregí dos suposiciones que tenía anotadas, midiendo en vez de asumir:

**La suite ya NO destruye la base de dev.** `phpunit.xml` fija
`DB_DATABASE=hotelignite_testing`. Verificado corriéndola entera: dev quedó
intacta (75 tablas, 77 alojamientos, 1356 reservas). La regla de usar siempre
`--filter` dejó de ser necesaria.

**Pero los tests están rojos: `86 failed, 9 passed`.** Y se rompieron solos, sin
que nadie tocara la tenencia:

- Los 8 tests con `RefreshDatabase` corren `migrate:fresh` y chocan con el punto 5.
- Los 5 con `DatabaseTransactions` —incluidos `AccommodationTenancyTest` y
  `ChildResourceTenancyTest`— insertan fixtures contra un esquema que ya cambió.
  `ChildResourceTenancyTest::createRoomType` inserta `name`, `created` y
  `modified` en `room_types`; ninguna de las tres columnas existe hoy (`name` se
  movió a `room_type_descriptions`, las otras dos a `created_at`/`updated_at`).

Es el argumento entero a favor de tener CI, en un caso concreto: **la red de
seguridad de la tenencia lleva semanas caída y nadie se enteró.** Es justo lo más
caro que puede romperse acá, y es lo que hay que tener verde antes de tocar el
framework.

Plan detallado en `docs/migrations-and-ci-plan.md`.

## Medios y bajos

**12. CSP — RESUELTO 31/08.** `docker/nginx.conf` agrega
`Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` con
`always` (sin ese flag nginx omite el header justo en las respuestas de error,
que son las que devuelven cuerpo generado por PHP). Es la política más
restrictiva posible y no rompe nada: no queda una sola vista Blade. Traefik ya
ponía HSTS, `nosniff`, `frameDeny` y `Referrer-Policy`.

<details><summary>Diagnóstico original</summary>

**12. Sin CSP ni `Permissions-Policy`.** Traefik ya pone HSTS (1 año,
`includeSubdomains`), `nosniff`, `frameDeny` y `referrerPolicy` — eso está bien
resuelto. Falta CSP. Para una API JSON pura el impacto es bajo, pero mientras
existan las vistas Blade del punto 1 hay superficie de XSS real. Si borrás las
vistas, alcanza con `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`.

</details>

**13. `docker.sock` montado en Traefik.** Está en `:ro`, pero el socket de Docker
en modo lectura sigue permitiendo enumerar y, en varias versiones, escalar. Quien
comprometa Traefik es root en el host. Alternativa: `tecnativa/docker-socket-proxy`
exponiendo sólo `CONTAINERS=1`.

**14. Purga de tokens — RESUELTO 31/08.** `app/Console/Kernel.php` corre
`sanctum:prune-expired --hours=24` y `queue:prune-failed --hours=168`, ambas
diarias, y `docker/supervisord.conf` tiene el `program:scheduler` que las
dispara (un loop de `schedule:run` cada 60s, para no sumarle cron al
contenedor).

<details><summary>Diagnóstico original</summary>

**14. Tokens Sanctum vencidos nunca se purgan.** `SANCTUM_TOKEN_EXPIRATION=1440`
marca los tokens como vencidos pero no los borra, y no hay scheduler
(`app/Console/Kernel.php` está vacío) ni proceso de cron en `supervisord.conf`.
`personal_access_tokens` crece sin techo. Junto con el worker de colas del punto
9, agregar un `program:scheduler` y `$schedule->command('sanctum:prune-expired --hours=24')->daily()`.

</details>

**15. `url` de imágenes.** `StoreAccommodationImageRequest` valida
`['required','string','url','max:2048']`. La regla `url` de Laravel usa
`filter_var`, que acepta esquemas raros (`javascript://%0a...`). Los navegadores
no ejecutan `javascript:` en `<img src>`, así que el riesgo práctico es bajo, pero
restringir a `https` es una línea: `Rule::in` no sirve acá, usá una regex o
`starts_with:https://`.

**16. Residuos — parcialmente resuelto 31/08.** `_docker-root-junk/` y
`docker/config/` **entraban a la imagen de producción**: el `.dockerignore` no
los excluía y el Dockerfile hace `COPY . .`. Ya están excluidos, junto con el
compose de producción y `backup.sh`, que tampoco tienen nada que hacer adentro.
Verificado sobre la imagen reconstruida. Siguen en el árbol local, donde son
inofensivos; borrarlos pide `sudo` porque son root-owned.

Lo demás del diagnóstico original sigue igual:

`_docker-root-junk/` y `docker/config/` son directorios
root-owned que sobraron de builds anteriores y no aportan nada.
`storage/` está en 777 en el árbol de desarrollo. `public/robots.txt`,
`welcome.blade.php` y `dashboard.blade.php` son huella innecesaria en un dominio
de API. `node_modules/` y `vendor/` en el árbol son root-owned (quedan fuera de la
imagen por `.dockerignore`, así que no afectan producción).

**17. Secretos en disco.** `docker/.env.production` vive en el VPS con `600` y
está correctamente gitignoreado (verificado con `git check-ignore`). Aceptable
para arrancar; anotalo como deuda para cuando haya más de una persona operando.

---

## Lo que ya está bien (no tocar)

Vale dejarlo escrito para no "arreglarlo" por las dudas más adelante:

- **Throttles por superficie**, no uno global: `login` 5/min por email+IP,
  `client` por tier del cliente B2B, `invitations` alto a propósito,
  `invitations-public` por IP. El diseño es correcto; sólo le falta infraestructura.
- **CORS por env con orígenes explícitos**, sin `*`, con `supports_credentials`.
- **MySQL en red `internal: true`**, sin puertos publicados al host.
- **Traefik**: TLS-ALPN, redirect permanente 80→443, HSTS con `includeSubdomains`.
- **Uploads de documentos**: `mimes` en whitelist, 20 MB, disco privado, descarga
  siempre por ruta autorizada con Policy y `assertOwned`. Bien hecho.
- **Imágenes son URLs, no uploads** — no hay superficie de subida de archivos
  arbitrarios en ese camino.
- **nginx**: `server_tokens off`, sólo `index.php` ejecuta PHP, dotfiles denegados,
  `client_max_body_size` acotado.
- **php.ini**: `expose_php=Off`, `display_errors=Off`, opcache sin revalidar
  timestamps, cookies de sesión `httponly`/`secure`/`samesite`.
- **Convención de 404 en cross-tenant** para no delatar existencia de recursos.
- **Abilities por `user_type` en el token** como defensa en profundidad sobre las
  Policies.

---

## Orden sugerido

**Antes de exponer el dominio:** todo hecho salvo dos cosas que dependen de
tener el VPS delante:

1. Configurar el destino **offsite** del backup (`RCLONE_REMOTE` o
   `BACKUP_RSYNC` en `.env.production`) y correr `./backup.sh verify` una vez.
   El script está y funciona; sin destino externo el backup vive en el mismo
   disco que la base y avisa en cada corrida.
2. Monitoreo de errores (`sentry/sentry-laravel` o equivalente). Es lo único que
   queda del punto 8: los errores ya salen en JSON, pero si algo explota a las
   3 AM la evidencia son `docker logs` rotados a 10 MB × 5.

**Antes de abrir a clientes B2B reales (semanas):**
3. Terminar B8 — tenencia de recursos hijos (punto 6). Es el bloqueante
   funcional más serio que queda: en los endpoints todavía no cubiertos un
   usuario de la cuenta A puede llegar a recursos hijos de la cuenta B.
4. CI en GitHub Actions con la suite como check requerido (punto 11). Ahora es
   barato: `migrate` corre desde cero, así que la base de tests se levanta sola.
5. Cloudflare delante (punto 3.3), sumando sus rangos a `TRUSTED_PROXIES` **y** a
   `forwardedHeaders.trustedIPs` de Traefik. Las dos cosas juntas o vuelve el
   agujero del `X-Forwarded-For`.

**Deuda menor:** puntos 13 (`docker.sock`), 15 (esquema de `url` en imágenes) y
16 (los directorios root-owned `_docker-root-junk/` y `docker/config/`, que ya
no entran a la imagen pero siguen en el árbol local; borrarlos pide `sudo`).
