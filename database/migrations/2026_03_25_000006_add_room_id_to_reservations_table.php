<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin FK a `rooms`: en la BD legacy `rooms.id` es `int`, incompatible con el
     * `bigint unsigned` de `foreignId()`. Se deja la columna indexada y la
     * integridad a nivel app, como el resto del esquema legacy.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'room_id')) {
                $table->unsignedBigInteger('room_id')->nullable()->after('accommodation_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('room_id');
        });
    }
};
