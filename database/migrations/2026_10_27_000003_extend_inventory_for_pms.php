<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario del PMS (reglas acordadas con hotelería):
 *
 * - `room_types.code`: código corto (2-6, mayúsculas) único por alojamiento. Es
 *   la clave con la que un channel manager mapea el tipo.
 * - `room_types.max_adults` / `max_children`: la ocupación máxima total ya
 *   existía; éstos acotan cada grupo.
 * - `rooms.features`: características como etiquetas (vista, accesible…), sin
 *   lógica todavía.
 * - `rooms.archived_at`: baja lógica. Una habitación que tuvo reservas no se
 *   borra: se da de baja, sale del rack y del cupo, y conserva el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('code', 10)->nullable();
            $table->unsignedTinyInteger('max_adults')->nullable();
            $table->unsignedTinyInteger('max_children')->nullable();

            $table->unique(['accommodation_id', 'code']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->jsonb('features')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['features', 'archived_at']);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropUnique(['accommodation_id', 'code']);
            $table->dropColumn(['code', 'max_adults', 'max_children']);
        });
    }
};
