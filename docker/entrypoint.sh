#!/bin/sh
# Entrypoint de producción del API:
#   - valida APP_KEY
#   - espera a MySQL
#   - migra (si RUN_MIGRATIONS=true)
#   - cachea config, rutas y vistas
set -e

APP_DIR=/var/www
cd "$APP_DIR"

log() { echo "[entrypoint] $*"; }

# --------------------------------------------------------------- storage
# El volumen persistente monta sobre storage/. Docker precarga un volumen
# nombrado vacío con lo que trae la imagen, así que acá solo garantizamos
# los directorios y los permisos.
for d in app/public framework/cache/data framework/sessions framework/views logs; do
    mkdir -p "$APP_DIR/storage/$d"
done
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# --------------------------------------------------------------- one-off
# `docker compose run api php artisan ...` no necesita esperar la base ni
# recachear nada: ejecutamos el comando y salimos. El arranque completo es
# solo el del servicio (CMD supervisord).
if [ "${1:-}" != "supervisord" ]; then
    log "comando puntual: $*"
    exec "$@"
fi

# --------------------------------------------------------------- checks
if [ -z "${APP_KEY:-}" ]; then
    log "ERROR: APP_KEY vacío. Generá una con:"
    log "  docker compose --env-file .env.production -f docker-compose.prod.yml run --rm --no-deps api php artisan key:generate --show"
    exit 1
fi

# --------------------------------------------------------------- MySQL
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
WAIT_FOR_DB_TIMEOUT="${WAIT_FOR_DB_TIMEOUT:-60}"

log "esperando a MySQL en $DB_HOST:$DB_PORT (máx ${WAIT_FOR_DB_TIMEOUT}s)"
waited=0
while ! php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT"), $e, $s, 2) ? 0 : 1);' 2>/dev/null; do
    waited=$((waited + 2))
    if [ "$waited" -ge "$WAIT_FOR_DB_TIMEOUT" ]; then
        log "ERROR: MySQL no respondió en ${WAIT_FOR_DB_TIMEOUT}s"
        exit 1
    fi
    sleep 2
done
log "MySQL disponible"

# --------------------------------------------------------------- Laravel
php artisan config:clear >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "ejecutando migraciones"
    php artisan migrate --force
fi

log "cacheando config, rutas y vistas"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# nginx sirve /storage por alias, pero Laravel espera el symlink igual.
php artisan storage:link >/dev/null 2>&1 || true

# artisan corrió como root: devolvemos todo a www-data para que los workers
# de php-fpm puedan escribir logs y cache.
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

log "arrancando: $*"
exec "$@"
