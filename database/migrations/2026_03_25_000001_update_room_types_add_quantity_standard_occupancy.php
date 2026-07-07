<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('quantity')->nullable()->after('quantity_max');
            $table->unsignedTinyInteger('standard_occupancy')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'standard_occupancy']);
        });
    }
};
