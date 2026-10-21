<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Experiencias (docs/experiences-plan.md): excursiones y actividades que se
 * publican en el portal de un client. Reemplazan a `tours`, que se borra cuando
 * turinorte deje de consumirlo.
 *
 * - `client_id`: en qué portal sale. A diferencia de eventos y POI, el staff lo
 *   elige desde el CRM; NULL = cargada por el staff sin asignar (no sale en
 *   ningún portal).
 * - `operating_days` (ISO, 1 = lunes) y `season_months` (1..12) son jsonb; NULL
 *   = todos los días / todo el año. No hay tabla de salidas en el MVP.
 * - `city_experience`: destinos que recorre además de la ciudad de salida.
 *
 * Las columnas que apuntan a `cities` son `integer` y no `foreignId()` porque
 * `cities.id` es `int`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('icon', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_category_id')->constrained('experience_categories')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->integer('departure_city_id');
            $table->foreign('departure_city_id')->references('id')->on('cities')->restrictOnDelete();
            $table->foreignId('point_of_interest_id')->nullable()->constrained('points_of_interest')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();

            $table->decimal('duration_value', 5, 1)->nullable();
            $table->string('duration_unit', 10)->nullable();
            $table->string('difficulty', 10)->nullable();
            $table->unsignedSmallInteger('min_age')->nullable();
            $table->unsignedSmallInteger('group_min')->nullable();
            $table->unsignedSmallInteger('group_max')->nullable();
            $table->jsonb('languages')->nullable();
            $table->jsonb('includes')->nullable();
            $table->jsonb('excludes')->nullable();
            $table->text('what_to_bring')->nullable();

            $table->string('meeting_point_name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable()->storedAs(
                'CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL '
                .'THEN ST_SetSRID(ST_MakePoint(longitude::float8, latitude::float8), 4326)::geography END'
            );

            $table->string('price_type', 10)->default('unknown');
            $table->decimal('price_from', 12, 2)->nullable();
            $table->string('currency', 3)->default('ARS');
            $table->string('price_unit', 10)->default('person');

            $table->jsonb('operating_days')->nullable();
            $table->jsonb('season_months')->nullable();

            $table->string('operator_name')->nullable();
            $table->string('operator_email')->nullable();
            $table->string('operator_phone', 50)->nullable();
            $table->string('booking_url')->nullable();
            $table->jsonb('links')->nullable();

            $table->string('status', 10)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->string('source', 20)->default('manual');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source', 'external_id']);
            $table->index(['client_id', 'status']);
            $table->index('departure_city_id');
            $table->spatialIndex('location');
        });

        Schema::create('city_experience', function (Blueprint $table) {
            $table->id();
            $table->integer('city_id');
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
            $table->foreignId('experience_id')->constrained('experiences')->cascadeOnDelete();

            $table->unique(['experience_id', 'city_id']);
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_experience');
        Schema::dropIfExists('experiences');
        Schema::dropIfExists('experience_categories');
    }
};
