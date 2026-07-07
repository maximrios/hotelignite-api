<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('room_categories')->nullOnDelete();
            $table->string('slug')->nullable()->unique();
            $table->decimal('size', 6, 2)->nullable();              // superficie en m²
            $table->unsignedTinyInteger('max_occupancy')->nullable();      // capacidad máxima de personas
            $table->unsignedTinyInteger('standard_occupancy')->nullable(); // ocupación base (sin suplemento)
            $table->unsignedTinyInteger('quantity')->nullable();           // cantidad de habitaciones de este tipo
            $table->unsignedTinyInteger('quantity_max')->nullable();       // campo legacy — usar quantity
            $table->enum('status', ['active', 'inactive', 'maintenance'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
