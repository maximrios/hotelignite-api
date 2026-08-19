<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote RoomType ↔ Service (RoomTypeService). Espeja `accommodation_services`.
     * `service_id` queda sin FK a nivel DB porque `services.id` es `int` legacy
     * (no convertido) — la integridad es a nivel app, como en el resto del esquema.
     */
    public function up(): void
    {
        Schema::create('room_type_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->unsignedBigInteger('service_id');
            $table->timestamps();

            $table->unique(['room_type_id', 'service_id']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_services');
    }
};
