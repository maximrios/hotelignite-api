<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos, multimedia y propiedad por client (docs/events-plan.md).
 *
 * - `points_of_interest.client_id`: quién lo cargó. NULL = staff. Un client sólo
 *   edita lo propio; el staff, todo.
 * - `events`: fechas como `date` + `time` en hora local del destino, no como
 *   instante UTC (ver el plan). `weekdays` es jsonb con días ISO (1 = lunes) y
 *   sólo aplica a `recurrence = weekly`.
 * - `media`: polimórfica y aparte de `images`, que usan alojamientos y
 *   habitaciones. Guarda la URL y, para YouTube, el id; nunca HTML de un iframe.
 *
 * `city_id` es `integer` y no `foreignId()` porque `cities.id` es `int`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('points_of_interest', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('poi_category_id')
                ->constrained('clients')->restrictOnDelete();
            $table->index('client_id');
        });

        Schema::create('event_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('icon', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->integer('city_id');
            $table->foreign('city_id')->references('id')->on('cities')->restrictOnDelete();
            $table->foreignId('event_category_id')->constrained('event_categories')->restrictOnDelete();
            $table->foreignId('point_of_interest_id')->nullable()->constrained('points_of_interest')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('recurrence', 10)->default('none');
            $table->jsonb('weekdays')->nullable();
            $table->string('status', 20)->default('scheduled');

            $table->string('venue_name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable()->storedAs(
                'CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL '
                .'THEN ST_SetSRID(ST_MakePoint(longitude::float8, latitude::float8), 4326)::geography END'
            );

            $table->string('price_type', 10)->default('unknown');
            $table->decimal('price_from', 12, 2)->nullable();
            $table->decimal('price_to', 12, 2)->nullable();
            $table->string('currency', 3)->default('ARS');
            $table->string('ticket_url')->nullable();

            $table->string('organizer_name')->nullable();
            $table->string('organizer_email')->nullable();
            $table->string('organizer_phone', 50)->nullable();

            $table->jsonb('links')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('source', 20)->default('manual');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source', 'external_id']);
            $table->index(['city_id', 'start_date']);
            $table->index('client_id');
            $table->spatialIndex('location');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('type', 10);
            $table->string('provider', 20);
            $table->string('url', 2048);
            $table->string('provider_id')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_categories');

        Schema::table('points_of_interest', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
