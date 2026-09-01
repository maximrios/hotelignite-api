#!/usr/bin/env bash
#
# Backup de la base y del volumen `storage` del stack de producción.
#
#   ./backup.sh              # dump + tar + rotación (lo que corre el cron)
#   ./backup.sh verify       # restaura el último dump en una base descartable
#   ./backup.sh list         # qué hay guardado
#
# Pensado para correr en el VPS, desde `api/docker/`, con el stack levantado.
# Un backup que nunca se restauró no es un backup: `verify` existe para eso y
# conviene correrlo a mano después del primero y cada vez que cambie el esquema.
#
set -euo pipefail

cd "$(dirname "$0")"

DB_CONTAINER="${DB_CONTAINER:-hi-db}"
STORAGE_VOLUME="${STORAGE_VOLUME:-hotelignite-prod_storage}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/hotelignite}"
KEEP_DAILY="${KEEP_DAILY:-7}"
KEEP_WEEKLY="${KEEP_WEEKLY:-4}"

# Destino fuera del VPS. Sin esto el backup vive en el mismo disco que puede
# perderse: cubre el `docker compose down -v` mal tipeado, no el incendio.
# Formato: cualquier destino que entienda rclone (`s3:bucket/path`,
# `b2:bucket/path`) o, si RCLONE_REMOTE está vacío, un destino rsync en
# BACKUP_RSYNC (`usuario@host:/ruta`).
RCLONE_REMOTE="${RCLONE_REMOTE:-}"
BACKUP_RSYNC="${BACKUP_RSYNC:-}"

log() { printf '[%s] %s\n' "$(date +%FT%T)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date +%FT%T)" "$*" >&2; exit 1; }

[[ -f .env.production ]] || die "falta .env.production (ver DEPLOY.md §2)"

# `set -a` exporta lo que se lea; el subshell evita ensuciar el entorno del
# resto del script con todas las variables de la app.
load_env() {
    set -a
    # shellcheck disable=SC1091
    source ./.env.production
    set +a
}
load_env

: "${DB_DATABASE:?falta DB_DATABASE en .env.production}"
: "${DB_ROOT_PASSWORD:?falta DB_ROOT_PASSWORD en .env.production}"

docker inspect "$DB_CONTAINER" >/dev/null 2>&1 || die "el contenedor $DB_CONTAINER no existe (¿el stack está levantado?)"

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"

# ---------------------------------------------------------------- backup

do_backup() {
    local stamp dump storage_tar
    stamp="$(date +%F-%H%M)"
    dump="$BACKUP_DIR/daily/db-$stamp.sql.gz"
    storage_tar="$BACKUP_DIR/daily/storage-$stamp.tar.gz"

    # --single-transaction: dump consistente sin lockear las tablas InnoDB, o
    # sea sin cortar el servicio mientras dura.
    # La contraseña va por variable de entorno del propio mysqldump y no por
    # línea de comandos: en `docker exec ... -p$PASS` queda visible en el `ps`
    # del host.
    log "dump de $DB_DATABASE"
    docker exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" "$DB_CONTAINER" \
        mysqldump -uroot --single-transaction --quick --routines --triggers \
        --default-character-set=utf8mb4 "$DB_DATABASE" \
        | gzip -9 > "$dump.tmp"

    # El pipe a gzip enmascara un mysqldump que falla: sin este chequeo el cron
    # guardaría felizmente un .sql.gz truncado y nadie se enteraría hasta el día
    # que haya que restaurarlo.
    if ! gzip -t "$dump.tmp" 2>/dev/null || [[ ! -s "$dump.tmp" ]]; then
        rm -f "$dump.tmp"
        die "el dump salió vacío o corrupto"
    fi
    if ! zcat "$dump.tmp" | tail -5 | grep -q 'Dump completed'; then
        rm -f "$dump.tmp"
        die "el dump no terminó (falta el marcador 'Dump completed')"
    fi
    mv "$dump.tmp" "$dump"
    log "ok: $dump ($(du -h "$dump" | cut -f1))"

    log "tar del volumen $STORAGE_VOLUME"
    docker run --rm -v "$STORAGE_VOLUME":/s:ro -v "$BACKUP_DIR/daily":/b alpine \
        tar czf "/b/$(basename "$storage_tar").tmp" -C /s . >/dev/null
    mv "$storage_tar.tmp" "$storage_tar"
    log "ok: $storage_tar ($(du -h "$storage_tar" | cut -f1))"

    # El backup semanal es una copia, no un movimiento: el diario tiene que
    # seguir contando para su propia retención.
    if [[ "$(date +%u)" == "7" ]]; then
        cp "$dump" "$BACKUP_DIR/weekly/"
        cp "$storage_tar" "$BACKUP_DIR/weekly/"
        log "copia semanal guardada"
    fi

    rotate
    offsite
}

rotate() {
    local kind keep
    for kind in daily weekly; do
        keep=$([[ "$kind" == daily ]] && echo "$KEEP_DAILY" || echo "$KEEP_WEEKLY")
        for prefix in db storage; do
            # `ls -t` ordena por fecha; se borra todo lo que sobra del tope.
            find "$BACKUP_DIR/$kind" -maxdepth 1 -name "$prefix-*" -printf '%T@ %p\n' 2>/dev/null \
                | sort -rn | tail -n "+$((keep + 1))" | cut -d' ' -f2- \
                | while read -r old; do
                    log "rotación: borro $(basename "$old")"
                    rm -f "$old"
                done
        done
    done
}

offsite() {
    if [[ -n "$RCLONE_REMOTE" ]]; then
        command -v rclone >/dev/null || die "RCLONE_REMOTE está seteado pero rclone no está instalado"
        log "sincronizando a $RCLONE_REMOTE"
        rclone sync "$BACKUP_DIR" "$RCLONE_REMOTE" --transfers=2 --checksum
        log "offsite ok"
    elif [[ -n "$BACKUP_RSYNC" ]]; then
        log "sincronizando a $BACKUP_RSYNC"
        rsync -az --delete "$BACKUP_DIR/" "$BACKUP_RSYNC"
        log "offsite ok"
    else
        log "AVISO: sin destino offsite (RCLONE_REMOTE / BACKUP_RSYNC vacíos)."
        log "       El backup está en el mismo disco que la base: no cubre la pérdida del VPS."
    fi
}

# ---------------------------------------------------------------- verify

do_verify() {
    local latest scratch tables
    latest="$(find "$BACKUP_DIR/daily" -maxdepth 1 -name 'db-*.sql.gz' -printf '%T@ %p\n' \
        | sort -rn | head -1 | cut -d' ' -f2-)"
    [[ -n "$latest" ]] || die "no hay ningún dump en $BACKUP_DIR/daily"

    scratch="verify_$(date +%s)"
    log "restaurando $(basename "$latest") en la base descartable $scratch"

    docker exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" "$DB_CONTAINER" \
        mysql -uroot -e "DROP DATABASE IF EXISTS \`$scratch\`; CREATE DATABASE \`$scratch\`;"

    # trap para que un fallo a mitad no deje la base de verificación colgada
    # ocupando disco en el servidor.
    trap 'docker exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" "$DB_CONTAINER" mysql -uroot -e "DROP DATABASE IF EXISTS \`'"$scratch"'\`;" || true' EXIT

    zcat "$latest" | docker exec -i -e MYSQL_PWD="$DB_ROOT_PASSWORD" "$DB_CONTAINER" \
        mysql -uroot "$scratch"

    tables="$(docker exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" "$DB_CONTAINER" \
        mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$scratch';")"

    [[ "$tables" -gt 0 ]] || die "la restauración dejó 0 tablas"

    log "restauración ok: $tables tablas"
    log "conteos de control:"
    docker exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" "$DB_CONTAINER" mysql -uroot -t -e "
        SELECT 'accommodations' AS tabla, COUNT(*) AS filas FROM \`$scratch\`.accommodations
        UNION ALL SELECT 'users', COUNT(*) FROM \`$scratch\`.users
        UNION ALL SELECT 'reservations', COUNT(*) FROM \`$scratch\`.reservations;"
}

do_list() {
    local kind
    for kind in daily weekly; do
        printf '\n== %s ==\n' "$kind"
        ls -lh "$BACKUP_DIR/$kind" 2>/dev/null | tail -n +2 || true
    done
}

case "${1:-backup}" in
    backup) do_backup ;;
    verify) do_verify ;;
    list)   do_list ;;
    *)      die "uso: $0 [backup|verify|list]" ;;
esac
