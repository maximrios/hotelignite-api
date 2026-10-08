<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote City ↔ Tour. Sin FKs: en la BD legacy `cities.id` y `tours.id` son
     * `int`, incompatibles con el `bigint unsigned` de `foreignId()`.
     */
    public function up(): void
    {
        if (Schema::hasTable('city_tour')) {
            return;
        }

        Schema::create('city_tour', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id')->index();
            $table->unsignedBigInteger('tour_id')->index();
            $table->timestamps();
            $table->unique(['city_id', 'tour_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_tour');
    }
};
