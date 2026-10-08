<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global de puntos de interés (docs/points-of-interest-plan.md).
 *
 * - `poi_categories`: dos niveles (raíz → hoja). Un POI siempre cuelga de una
 *   hoja; los radios de búsqueda viven en la raíz y la hoja puede pisarlos.
 *   `nearest_limit` marca las hojas que se buscan por cercanía sin radio (el
 *   aeropuerto: siempre se muestran los N más cercanos, estén a 5 o a 150 km).
 * - `points_of_interest.location`: generada desde `latitude`/`longitude`, igual
 *   que en `accommodations`. Acá las coordenadas son `numeric` validadas por el
 *   FormRequest, así que alcanza con que ambas estén presentes.
 *
 * `city_id` es `integer` y no `foreignId()` porque `cities.id` es `int`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poi_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('poi_categories')->restrictOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('icon', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('default_radius_m')->nullable();
            $table->unsignedSmallInteger('nearest_limit')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('points_of_interest', function (Blueprint $table) {
            $table->id();
            $table->integer('city_id');
            $table->foreign('city_id')->references('id')->on('cities')->restrictOnDelete();
            $table->foreignId('poi_category_id')->constrained('poi_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable()->storedAs(
                'CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL '
                .'THEN ST_SetSRID(ST_MakePoint(longitude::float8, latitude::float8), 4326)::geography END'
            );
            $table->boolean('is_featured')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('source', 20)->default('manual');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source', 'external_id']);
            $table->index(['city_id', 'poi_category_id']);
            $table->spatialIndex('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_of_interest');
        Schema::dropIfExists('poi_categories');
    }
};
