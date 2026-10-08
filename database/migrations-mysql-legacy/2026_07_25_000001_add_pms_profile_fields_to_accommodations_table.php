<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de la ficha del establecimiento que el PMS ya editaba pero la tabla no
 * tenía: el PATCH los descartaba en silencio (`$request->validated()` filtra por
 * reglas) y el usuario veía "Cambios guardados" sin que se guardara nada.
 *
 * Todos nullable: la tabla es legacy y tiene filas cargadas. Ojo con el charset
 * — `accommodations` es latin1 y estas columnas nacen con el default de la
 * conexión (utf8mb4). MySQL admite la mezcla; se deja así a propósito para que
 * `legal_name` y `landmark` acepten acentos y ñ sin tocar la tabla entera.
 *
 * Idempotente por columna: la BD de dev viene de un dump y puede tener alguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            if (! Schema::hasColumn('accommodations', 'legal_name')) {
                $table->string('legal_name', 255)->nullable()->after('name');
            }

            // Categoría en estrellas (1–5). Convive con `type_id`: los tipos 1–5
            // son "hotel por estrellas" y se siguen usando para agrupar en los
            // filtros, pero un apart u hostel también puede estar categorizado.
            if (! Schema::hasColumn('accommodations', 'stars')) {
                $table->unsignedTinyInteger('stars')->nullable()->after('type_id');
            }

            // IANA time zone, ej. "America/Argentina/Salta". 64 alcanza: el
            // identificador más largo del catálogo tiene 32 caracteres.
            if (! Schema::hasColumn('accommodations', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('currency_id');
            }

            if (! Schema::hasColumn('accommodations', 'address2')) {
                $table->string('address2', 255)->nullable()->after('address');
            }

            // Referencia de ubicación en texto libre ("frente al Centro Cívico").
            if (! Schema::hasColumn('accommodations', 'landmark')) {
                $table->string('landmark', 255)->nullable()->after('address2');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            foreach (['legal_name', 'stars', 'timezone', 'address2', 'landmark'] as $column) {
                if (Schema::hasColumn('accommodations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
