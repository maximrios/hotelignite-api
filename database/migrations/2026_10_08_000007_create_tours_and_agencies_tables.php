<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 7/9: turismo (tours y su vínculo con
 * ciudades) y agencias de viaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tours', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug');
            $table->text('description');
            $table->integer('channel_id')->nullable();
            $table->string('web')->nullable();
        });

        Schema::create('city_tour', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id')->index();
            $table->unsignedBigInteger('tour_id')->index();
            $table->timestamps();

            $table->unique(['city_id', 'tour_id']);
        });

        Schema::create('travel_agencies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->default('');
            $table->string('logo')->default('');
            $table->string('name')->default('');
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->string('identity')->default('');
            $table->string('web')->default('');
            $table->string('email')->default('');
            $table->string('phone')->default('');
            $table->smallInteger('published')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_agencies');
        Schema::dropIfExists('city_tour');
        Schema::dropIfExists('tours');
    }
};
