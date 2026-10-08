<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensiones de Postgres:
 * - `unaccent`: base para búsquedas que ignoren acentos ("embarcacion" →
 *   "Embarcación"). Viene en contrib, incluida en cualquier imagen oficial.
 * - `postgis`: tipos y funciones geográficas, para pasar `latitude`/`longitude`
 *   (hoy varchar) a `geography` y buscar por distancia. Requiere la imagen
 *   `postgis/postgis` (ver docker-compose); con `postgres:17-alpine` a secas
 *   esta migración falla.
 *
 * Crearlas exige un rol con permiso de CREATE en la base; en dev y en el VPS el
 * usuario de la app es el POSTGRES_USER del contenedor, que lo tiene.
 *
 * Postgis crea la tabla `spatial_ref_sys` en `public`; Laravel ya la excluye de
 * `db:wipe` / `migrate:fresh` (`dont_drop` por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS postgis');
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
