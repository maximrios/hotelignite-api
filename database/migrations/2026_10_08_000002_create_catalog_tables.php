<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 2/9: catálogos globales (geografía, idiomas,
 * tipos, servicios, canales, planes y features, estados de reserva).
 *
 * Las tablas legacy conservan sus defaults `'0'` en columnas de texto: el código
 * viejo inserta omitiéndolas y cuenta con que no fallen por NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 150);
            $table->string('iso_code', 3);
        });

        // Catálogo mundial (~4119 filas), no sólo provincias argentinas.
        Schema::create('states', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 30);
            $table->integer('country_id')->default(1);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->increments('id');
            // "Sin provincia" se guarda como 0, no como NULL (legacy).
            $table->integer('state_id')->default(0);
            $table->string('name')->default('0');
            $table->string('slug')->nullable()->unique();
            $table->string('image')->default('0');
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->string('id', 4)->primary();
            $table->string('name')->default('0');
            $table->string('icon')->default('0');
            $table->smallInteger('order')->default(0);
        });

        Schema::create('accommodation_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
        });

        Schema::create('room_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
            $table->string('description')->default('0');
            $table->string('code')->default('0');
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
            $table->string('ico')->default('0');
            $table->boolean('enabled')->default(false);
            $table->enum('type', ['general', 'room', 'bathroom', 'accessibility', 'kitchen'])->default('general');
            $table->boolean('is_highlighted')->default(false);
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
            $table->enum('business_type', ['direct', 'ota', 'agency', 'gds', 'corporate', 'tour_operator', 'metasearch'])->default('direct');
            $table->enum('connection_type', ['manual', 'api', 'ical', 'gds', 'email'])->default('manual');
            $table->string('code', 20)->nullable()->unique();
            $table->string('first_name')->default('0');
            $table->string('last_name')->default('0');
            $table->string('phone')->default('0');
            $table->string('email')->default('0');
            $table->string('web')->default('0');
            $table->boolean('enabled')->default(true);
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->smallInteger('feedback')->default(0);
            $table->boolean('ota')->default(false);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
            $table->string('subtitle', 150)->nullable();
            $table->string('slug', 100)->nullable()->unique();
            $table->string('description')->default('0');
            // Centavos. bigint: el `int unsigned` de MySQL no entra en un
            // `integer` con signo de Postgres.
            $table->bigInteger('price')->default(0);
            $table->string('currency', 3)->default('ARS');
            $table->enum('billing_period', ['free', 'monthly', 'yearly'])->default('free');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('module', 40);
            $table->enum('type', ['boolean', 'limit'])->default('boolean');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_feature', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->index();
            $table->foreignId('feature_id')->index()->constrained('features')->cascadeOnDelete();
            // null = ilimitado / no aplica a features boolean.
            $table->integer('limit')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_id']);
        });

        Schema::create('account_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('entity_type')->nullable();
            $table->enum('owner_level', ['account', 'entity'])->default('entity');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Modelo `Status`.
        Schema::create('reservations_status', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('description')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations_status');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('account_types');
        Schema::dropIfExists('plan_feature');
        Schema::dropIfExists('features');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('services');
        Schema::dropIfExists('room_categories');
        Schema::dropIfExists('accommodation_types');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
