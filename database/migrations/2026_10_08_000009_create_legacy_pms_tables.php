<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 9/9: tablas del PMS legacy.
 *
 * Ningún modelo ni query de `app/` las usa (ver `docs/migrations-and-ci-plan.md`),
 * pero varias guardan el histórico operativo: habitaciones por reserva, cuenta
 * corriente, huéspedes por reserva, caja. Se crean para que la copia desde MySQL
 * no pierda datos. Candidatas a archivar y dropear en una ventana aparte.
 *
 * Diferencias con MySQL:
 * - Las fechas `NOT NULL DEFAULT '0000-00-00 ...'` pasan a nullable: la copia
 *   convierte las fechas cero en NULL.
 * - `reservation_id` pasa de int a varchar(36), el tipo de `reservations.id`
 *   (Postgres no compara int con varchar en un join).
 *
 * Se omiten a propósito las `oauth_*` (Passport, sacado de composer) y
 * `password_resets` (reemplazada por `password_reset_tokens`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reservation_id', 36)->default('0')->index();
            $table->integer('rate_plan_id');
            $table->integer('room_type_id')->default(0);
            $table->integer('room_id')->default(0);
            $table->date('arrival_room')->nullable();
            $table->date('departure_room')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('requirements')->nullable();
            $table->string('change')->nullable();
            $table->decimal('rate', 10, 2)->nullable();
            // 0/1/2: estado, no flag.
            $table->smallInteger('enabled')->default(0);
            $table->integer('user_created')->default(0);
            $table->integer('user_modified')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });

        Schema::create('reservations_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reservation_id', 36)->default('0')->index();
            $table->integer('room_type_id')->default(0);
            $table->integer('room_id')->default(0);
            $table->integer('extra_id')->default(0);
            $table->integer('concept_id')->default(0);
            $table->integer('product_id')->nullable()->default(0);
            $table->integer('quantity')->nullable()->default(0);
            $table->integer('payment_method_id')->default(0);
            $table->date('account_date')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('payment', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->string('currency_id', 50)->default('0');
            $table->integer('reference')->nullable();
            $table->dateTime('created')->nullable();
            $table->integer('user_created')->default(0);
            $table->dateTime('modified')->nullable();
            $table->integer('user_modified')->default(0);
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('reservations_accounts_concepts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
            $table->integer('type')->default(0);
        });

        Schema::create('reservations_extras', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reservation_id', 36)->default('0')->index();
            $table->integer('extra_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('user_created')->default(0);
            $table->dateTime('created')->nullable();
            $table->integer('user_modified')->default(0);
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->nullable();
        });

        Schema::create('reservations_guests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reservation_id', 36)->default('0')->index();
            $table->integer('guest_id')->default(0)->index();
            $table->smallInteger('headline')->default(0);
            $table->integer('user_created')->default(0);
            $table->dateTime('created')->nullable();
            $table->integer('user_modified')->default(0);
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        // Datos de tarjeta cifrados por la app legacy (blob). Vacía.
        Schema::create('reservations_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reservation_id', 36)->nullable();
            $table->binary('number')->nullable();
            $table->binary('expiration')->nullable();
            $table->binary('code')->nullable();
            $table->binary('holder')->nullable();
            $table->integer('card_id')->default(0);
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->integer('user_modified')->nullable();
            $table->smallInteger('enabled')->nullable();
        });

        Schema::create('reservations_vehicles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reservation_id', 36)->default('0');
            $table->string('brand', 50)->default('0');
            $table->string('model', 50)->default('0');
            $table->integer('year')->default(0);
            $table->string('domain', 50)->default('0');
            $table->smallInteger('type')->default(0);
            $table->string('assigned', 50)->default('0');
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('cash', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->integer('number')->default(0);
            $table->integer('user_id')->default(0);
            $table->dateTime('opened')->nullable();
            $table->decimal('beginning_balance', 10, 2)->default(0);
            $table->dateTime('closed')->nullable();
            $table->decimal('final_balance', 10, 2)->default(0);
            $table->integer('user_closed')->nullable();
            $table->decimal('real_balance', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->default(0);
            $table->integer('status_id')->default(0);
            $table->string('comment')->default('0');
            $table->text('currencies_detail')->nullable();
            $table->text('cards_detail')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('cash_concepts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->string('name')->default('0');
            $table->string('description')->default('0');
            $table->smallInteger('type')->default(0);
            $table->integer('user_created')->default(0);
            $table->dateTime('created')->nullable();
            $table->integer('user_modified')->default(0);
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('cash_flow', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cash_id')->default(0);
            $table->integer('accommodation_id')->default(0);
            $table->integer('user_id')->default(0);
            $table->integer('payment_method_id')->default(0);
            $table->integer('concept_id')->default(0);
            $table->smallInteger('flow_type')->default(0)->comment('1 = Incomes; 2 = Expenditures');
            $table->string('currency_id', 50)->default('0');
            $table->decimal('amount', 10, 2)->nullable()->default(0);
            $table->decimal('payment', 10, 2)->nullable()->default(0);
            $table->string('comment')->default('0');
            $table->integer('user_created')->default(0);
            $table->dateTime('created')->nullable();
            $table->integer('user_modified');
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('cash_status', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('0');
        });

        // Respaldo de la normalización de tipos de habitación: `rooms.room_type_id`
        // de las habitaciones legacy apunta a estos ids, no a `room_types`.
        Schema::create('room_types_old', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->string('name')->default('0');
            $table->integer('quantity_max')->default(0);
            $table->integer('quantity_min')->default(0);
            $table->integer('quantity_adults')->default(0);
            $table->integer('quantity_childrens')->default(0);
            $table->integer('category_id')->default(0);
            $table->integer('beds')->default(0);
            $table->integer('gender_id')->default(0);
            $table->integer('order')->default(0);
            $table->string('code', 50)->default('0');
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
            $table->integer('channel_id')->default(0);
        });

        Schema::create('room_type_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->default('0');
        });

        // Las tres `*_images` quedaron reemplazadas por la polimórfica `images`.
        Schema::create('accommodation_images', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->string('path')->nullable();
            $table->smallInteger('default')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->nullable();
        });

        Schema::create('room_images', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('room_type_id')->default(0);
            $table->string('path')->default('0');
            $table->string('thumb')->default('0');
            $table->smallInteger('default');
            $table->integer('user_created')->default(0);
            $table->dateTime('created')->nullable();
            $table->integer('user_modified')->default(0);
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
        });

        Schema::create('tour_images', function (Blueprint $table) {
            $table->increments('id');
            $table->string('path');
            $table->integer('tour_id');
            $table->timestamps();
        });

        Schema::create('tour_services', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tour_id');
            $table->integer('service_id');
            $table->text('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'tour_services', 'tour_images', 'room_images', 'accommodation_images',
            'room_type_categories', 'room_types_old', 'cash_status', 'cash_flow',
            'cash_concepts', 'cash', 'reservations_vehicles', 'reservations_payments',
            'reservations_guests', 'reservations_extras', 'reservations_accounts_concepts',
            'reservations_accounts', 'reservation_rooms',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
