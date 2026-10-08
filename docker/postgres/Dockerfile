# syntax=docker/dockerfile:1
#
# Postgres de HotelIgnite: PostgreSQL 17 + PostGIS 3.6 (de la imagen base) +
# pgvector (compilado acá). Lo usan el compose de dev (raíz del monorepo) y el
# del VPS, para que las dos bases tengan exactamente las mismas extensiones.
#
#   docker build -t hotelignite/pgsql -f docker/postgres/Dockerfile docker/postgres
#
# - postgis  → ubicaciones (geography, ST_DWithin, ...).
# - vector   → embeddings para búsqueda semántica (pgvector).
# - unaccent → viene en contrib, no hace falta nada.
#
# Se parte de la variante alpine a propósito: el datadir existente se inicializó
# con su ICU (collation es-AR). Pasar a la variante debian cambiaría la versión
# de ICU y obligaría a reindexar.

FROM postgis/postgis:17-3.6-alpine

ARG PGVECTOR_VERSION=0.8.7

# Postgres está compilado con JIT (--with-llvm), así que PGXS además genera
# bitcode con clang: hace falta la MISMA versión mayor de clang/llvm que la de
# la imagen (ver `CLANG` en pgxs/src/Makefile.global). Al subir de imagen base,
# revisar que coincida.
#
# OPTFLAGS="": pgvector compila por defecto con -march=native, y una imagen
# construida en una máquina puede romper con "illegal instruction" en otra con
# otro CPU (dev ≠ droplet).
RUN set -eux; \
    apk add --no-cache --virtual .pgvector-build git build-base clang21 llvm21; \
    git clone --branch "v${PGVECTOR_VERSION}" --depth 1 https://github.com/pgvector/pgvector.git /tmp/pgvector; \
    cd /tmp/pgvector; \
    make OPTFLAGS=""; \
    make install; \
    cd /; \
    rm -rf /tmp/pgvector; \
    apk del --no-network .pgvector-build; \
    test -f /usr/local/share/postgresql/extension/vector.control
