<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Descripciones multilenguaje de los tipos de habitación (RoomTypeDescription).
     * Espeja `accommodation_descriptions`: se filtra por `language_id` (ej. 'es').
     * La tabla existía en la BD legacy fuera de migraciones; se formaliza acá con
     * ids bigint para acompañar la conversión del resto del esquema.
     */
    public function up(): void
    {
        Schema::create('room_type_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('language_id', 10)->default('es');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['room_type_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_descriptions');
    }
};
