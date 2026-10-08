<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `accommodations.location`: punto geográfico derivado de `latitude`/`longitude`
 * para buscar por distancia (puntos de interés cercanos, ver
 * docs/points-of-interest-plan.md).
 *
 * Es una columna generada: se sigue escribiendo `latitude`/`longitude` y Postgres
 * recalcula el punto. Las coordenadas son varchar legacy con basura real (`'0'`,
 * `123123`, longitudes de `-195`), así que la expresión no puede fallar nunca —si
 * falla, falla el `ALTER` y cualquier INSERT posterior—: valida formato y rango
 * y devuelve NULL si algo no cierra. Los `CASE` anidados son a propósito: el
 * `AND` de Postgres no garantiza cortocircuito y el cast correría sobre texto
 * no numérico; `CASE` sí garantiza el orden.
 */
return new class extends Migration
{
    private const NUMERIC = "'^\\s*-?[0-9]+(\\.[0-9]+)?\\s*$'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $lat = 'latitude::float8';
        $lng = 'longitude::float8';

        $expression = 'CASE WHEN latitude ~ '.self::NUMERIC.' AND longitude ~ '.self::NUMERIC.' THEN '
            ."CASE WHEN {$lat} BETWEEN -90 AND 90 AND {$lng} BETWEEN -180 AND 180 AND NOT ({$lat} = 0 AND {$lng} = 0) "
            ."THEN ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography END END";

        Schema::table('accommodations', function (Blueprint $table) use ($expression) {
            $table->geography('location', subtype: 'point', srid: 4326)->nullable()->storedAs($expression);
            $table->spatialIndex('location');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropSpatialIndex(['location']);
            $table->dropColumn('location');
        });
    }
};
