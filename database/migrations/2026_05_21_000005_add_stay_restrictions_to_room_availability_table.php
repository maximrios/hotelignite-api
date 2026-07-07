<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_availability', function (Blueprint $table) {
            // Restricciones de estancia a nivel inventario (aplican a todos los rate plans)
            $table->unsignedSmallInteger('min_stay')->default(1)->after('closed_to_departure');
            $table->unsignedSmallInteger('max_stay')->nullable()->after('min_stay');
        });
    }

    public function down(): void
    {
        Schema::table('room_availability', function (Blueprint $table) {
            $table->dropColumn(['min_stay', 'max_stay']);
        });
    }
};
