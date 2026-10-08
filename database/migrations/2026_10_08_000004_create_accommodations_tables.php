<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 4/9: el alojamiento y lo que cuelga de él
 * (descripciones, servicios, políticas, canales, usuarios, imágenes).
 *
 * Las columnas `accommodation_id` siguen sin FK, igual que en MySQL. Allá era
 * por el choque `bigint` con signo vs `bigint unsigned`, que en Postgres ya no
 * existe; agregarlas ahora es posible, pero exige limpiar huérfanos antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('file_number', 50)->nullable();
            $table->string('tax_identification', 50)->default('0');
            $table->string('logo')->default('0');
            $table->integer('account_id')->default(0);
            $table->string('name')->default('0');
            $table->string('legal_name')->nullable();
            $table->string('address')->default('0');
            $table->string('address2')->nullable();
            $table->string('landmark')->nullable();
            $table->string('email')->default('0');
            $table->string('phone')->default('0');
            $table->string('email_reservations')->default('0');
            $table->string('phone_reservations')->default('0');
            $table->string('web')->default('0');
            $table->string('latitude')->default('0');
            $table->string('longitude')->default('0');
            $table->string('postal_code', 50)->default('0');
            $table->text('bank_data')->nullable();
            $table->integer('city_id')->default(0);
            $table->integer('state_id')->default(0);
            $table->string('country_id', 50)->default('0');
            $table->integer('type_id')->default(0);
            $table->unsignedTinyInteger('stars')->nullable();
            $table->string('language_id', 4)->default('0');
            $table->string('currency_id', 4)->default('0');
            $table->string('timezone', 64)->nullable();
            $table->string('token', 100)->default('0');
            $table->text('comment')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('active')->nullable();
            $table->boolean('test')->nullable();
            // Fuente de verdad de los entitlements (por alojamiento).
            $table->smallInteger('plan_id')->nullable();
            $table->date('expiration')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->integer('user_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('channel_code', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('accommodation_descriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->string('language_id', 50)->default('0');
            $table->string('introduction')->default('0');
            $table->text('description')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->nullable();
        });

        Schema::create('accommodation_services', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->integer('service_id')->default(0);
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('accommodation_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id')->unique();
            $table->time('checkin_from')->nullable();
            $table->time('checkin_to')->nullable();
            $table->time('checkout_from')->nullable();
            $table->time('checkout_to')->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->boolean('allow_children')->default(false);
            $table->unsignedTinyInteger('children_max_age')->nullable();
            $table->boolean('allow_pets')->default(false);
            $table->boolean('allow_smoking')->default(false);
            $table->boolean('allow_parties')->default(false);
            $table->boolean('payment_card')->default(false);
            $table->boolean('payment_cash')->default(false);
            $table->boolean('payment_transfer')->default(false);
            $table->boolean('payment_crypto')->default(false);
            $table->timestamps();
        });

        Schema::create('accommodation_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('accommodation_policies')->cascadeOnDelete();
            $table->string('language_id', 10)->default('es');
            $table->text('house_rules')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'language_id']);
        });

        Schema::create('accommodation_rate_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id')->index();
            $table->enum('rate_type', ['flexible', 'semi_flexible', 'non_refundable']);
            $table->unsignedTinyInteger('cancel_days')->nullable();
            $table->decimal('cancel_penalty', 5, 2)->nullable();
            $table->decimal('no_show_penalty', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['accommodation_id', 'rate_type']);
        });

        // Pivote "a qué canales estoy conectado YO". `commission_rate` null =
        // usar el default de `channels.commission_rate`.
        Schema::create('accommodation_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id')->index();
            $table->integer('channel_id')->index();
            $table->boolean('enabled')->default(true);
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->string('external_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['accommodation_id', 'channel_id']);
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });

        Schema::create('accommodation_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('accommodation_id')->index();
            $table->timestamps();

            $table->unique(['user_id', 'accommodation_id']);
        });

        // Polimórfica: Accommodation, RoomType, Tour y City.
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->morphs('imageable');
            $table->string('url');
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
        Schema::dropIfExists('accommodation_user');
        Schema::dropIfExists('accommodation_channels');
        Schema::dropIfExists('accommodation_rate_policies');
        Schema::dropIfExists('accommodation_policy_translations');
        Schema::dropIfExists('accommodation_policies');
        Schema::dropIfExists('accommodation_services');
        Schema::dropIfExists('accommodation_descriptions');
        Schema::dropIfExists('accommodations');
    }
};
