# syntax=docker/dockerfile:1
#
# Imagen de producción del API — Laravel 13 sobre PHP 8.4.
#
# Un solo contenedor con nginx + php-fpm supervisados por supervisord. El
# código y el vendor/ viajan dentro de la imagen (sin bind mounts) y opcache
# corre sin revalidar timestamps: para cambiar código hay que reconstruir.
#
# El contexto de build es api/, no la raíz del monorepo:
#   docker build -f docker/Dockerfile -t hotelignite/api .

FROM php:8.4-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# ------------------------------------------------------- sistema y extensiones
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        default-mysql-client \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        curl \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd opcache pcntl pdo_mysql zip \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# phpredis: lo necesita el store `redis` de cache. El rate limiter de Laravel se
# apoya en el cache por defecto, y sobre archivos hace I/O con lock en cada
# request: bajo un flood el limiter se vuelve parte del problema. Ver DEPLOY.md §7.
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# ------------------------------------------------------- configuración
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-hotelignite.ini
COPY docker/php-fpm.conf     /usr/local/etc/php-fpm.d/zz-hotelignite.conf
COPY docker/nginx.conf       /etc/nginx/conf.d/hotelignite.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www

# ------------------------------------------------------- dependencias
# Primero solo los manifests: mientras composer.lock no cambie, esta capa
# se reusa entre builds.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

# ------------------------------------------------------- código
COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php artisan package:discover --ansi; \
    for d in app/public framework/cache/data framework/sessions framework/views logs; do \
        mkdir -p "storage/$d"; \
    done; \
    rm -f bootstrap/cache/*.php; \
    chown -R www-data:www-data storage bootstrap/cache

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/healthz || exit 1

EXPOSE 80
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
