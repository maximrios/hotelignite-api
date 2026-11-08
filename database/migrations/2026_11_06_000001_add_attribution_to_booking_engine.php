<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde vino el huésped del motor (utm_source, utm_medium, utm_campaign): se toma al
 * apartar y pasa a cada reserva, para medir qué canal convierte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_holds', function (Blueprint $table) {
            $table->json('attribution')->nullable();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->json('attribution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_holds', fn (Blueprint $table) => $table->dropColumn('attribution'));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('attribution'));
    }
};
