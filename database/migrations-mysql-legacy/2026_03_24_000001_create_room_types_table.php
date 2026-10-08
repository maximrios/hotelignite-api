<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `room_types` ya existe en las BD restauradas del dump legacy. En ese caso
     * no se recrea: sólo se completan las columnas faltantes para dejarla
     * alineada con el esquema canónico de abajo.
     *
     * Sin FKs a `accommodations` / `room_categories`: en el legacy esos ids son
     * `bigint` con signo e `int` respectivamente, incompatibles con el
     * `bigint unsigned` de `foreignId()`. La integridad se hace cumplir en la app.
     */
    public function up(): void
    {
        if (! Schema::hasTable('room_types')) {
            Schema::create('room_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('accommodation_id')->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->string('slug')->nullable()->unique();
                $table->decimal('size', 6, 2)->nullable();              // superficie en m²
                $table->unsignedTinyInteger('max_occupancy')->nullable();      // capacidad máxima de personas
                $table->unsignedTinyInteger('standard_occupancy')->nullable(); // ocupación base (sin suplemento)
                $table->unsignedTinyInteger('quantity')->nullable();           // cantidad de habitaciones de este tipo
                $table->unsignedTinyInteger('quantity_max')->nullable();       // campo legacy — usar quantity
                $table->enum('status', ['active', 'inactive', 'maintenance'])->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('room_types', function (Blueprint $table) {
            if (! Schema::hasColumn('room_types', 'slug')) {
                $table->string('slug')->nullable()->unique();
            }
            if (! Schema::hasColumn('room_types', 'size')) {
                $table->decimal('size', 6, 2)->nullable();
            }
            if (! Schema::hasColumn('room_types', 'max_occupancy')) {
                $table->unsignedTinyInteger('max_occupancy')->nullable();
            }
            if (! Schema::hasColumn('room_types', 'quantity_max')) {
                $table->unsignedTinyInteger('quantity_max')->nullable();
            }
            if (! Schema::hasColumn('room_types', 'status')) {
                $table->enum('status', ['active', 'inactive', 'maintenance'])->nullable();
            }
            if (! Schema::hasColumn('room_types', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
