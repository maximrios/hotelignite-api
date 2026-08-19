<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotente: en las BD restauradas del dump `room_types` ya trae ambas
     * columnas, y el `create` de esta serie también las incluye.
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            if (! Schema::hasColumn('room_types', 'quantity')) {
                $table->unsignedTinyInteger('quantity')->nullable()->after('quantity_max');
            }
            if (! Schema::hasColumn('room_types', 'standard_occupancy')) {
                $table->unsignedTinyInteger('standard_occupancy')->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'standard_occupancy']);
        });
    }
};
