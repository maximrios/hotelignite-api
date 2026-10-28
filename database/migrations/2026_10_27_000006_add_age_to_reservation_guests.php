<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edad del pasajero en esa estadía, para cuando no se tiene la fecha de
 * nacimiento (hotelería: si sólo hay edad, se guarda y se marca menor igual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->dropColumn('age');
        });
    }
};
