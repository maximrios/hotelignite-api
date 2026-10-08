<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 6/9: huéspedes, reservas, pre-reservas
 * (`bookings`) y consultas (`inquiries`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name')->default('0');
            $table->string('last_name')->default('0');
            $table->enum('document_type', ['dni', 'passport', 'cuit', 'other'])->nullable();
            $table->string('document_number', 30)->nullable();
            $table->string('nationality', 3)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('identity_number', 50)->nullable();
            $table->date('birthday')->nullable();
            $table->string('email')->default('0');
            $table->string('address')->default('0');
            $table->string('phone')->default('0');
            $table->string('gender', 50)->default('0');
            $table->string('country_id', 50)->default('0');
            $table->integer('state_id')->default(0);
            $table->string('language_id', 50)->default('0');
            $table->integer('user_created')->nullable();
            $table->dateTime('created')->nullable();
            $table->integer('user_modified')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);

            $table->index(['document_type', 'document_number']);
        });

        // PK string: el modelo usa HasUuids, pero las 1356 reservas legacy
        // conservan su id numérico como texto ('1', '2', ...). Por eso no es
        // una columna `uuid` nativa: Postgres rechazaría esos valores.
        Schema::create('reservations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('source_reference')->nullable();
            $table->string('confirmation_number')->nullable()->unique();
            $table->integer('guest_id');
            $table->integer('accommodation_id');
            $table->unsignedBigInteger('room_id')->nullable()->index();
            $table->unsignedTinyInteger('extra_beds')->default(0);
            $table->unsignedTinyInteger('children')->default(0);
            $table->time('checkout_time')->nullable();
            $table->time('checkin_time')->nullable();
            $table->date('checkout_date')->nullable()->index();
            $table->date('checkin_date')->nullable()->index();
            $table->integer('channel_id')->nullable();
            $table->string('currency_id', 4);
            $table->string('language_id', 4);
            $table->integer('package_id');
            $table->string('regimen_id', 4)->nullable();
            $table->integer('additional_id')->nullable();
            $table->integer('discount_id')->nullable();
            $table->integer('cupon_id')->nullable();
            $table->string('country_id', 50)->nullable();
            $table->smallInteger('status_id')->index();
            $table->integer('payment_method_id');
            $table->integer('organization_id')->nullable();
            $table->date('arrival');
            $table->date('departure');
            $table->integer('nights')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('rate_total', 10, 2)->default(0);
            $table->integer('adults')->nullable();
            $table->integer('childrens')->nullable();
            $table->string('arrival_hour', 50)->nullable();
            $table->string('departure_hour', 50)->nullable();
            $table->dateTime('checkin')->nullable();
            $table->dateTime('checkout')->nullable();
            $table->smallInteger('reservation_type');
            $table->smallInteger('user_created')->nullable();
            $table->dateTime('created');
            $table->smallInteger('user_modified')->nullable();
            $table->dateTime('modified')->nullable();
            // En MySQL era NOT NULL con default '0000-00-00 00:00:00' (sic).
            $table->string('comment')->nullable();
            $table->string('voucher')->nullable();
            $table->string('token')->nullable();
            $table->smallInteger('enabled')->nullable();
            $table->string('wubook_code', 50)->nullable();
            $table->string('channel_code', 50)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('rate_plan_id')->nullable()->index()->constrained('rate_plans')->nullOnDelete();
            // Montos en centavos.
            $table->bigInteger('rate_amount')->nullable();
            $table->bigInteger('total_amount')->nullable();
            $table->string('currency', 3)->default('ARS');
            $table->bigInteger('commission_amount')->nullable();
            $table->enum('guarantee_type', ['credit_card', 'deposit', 'agency_voucher', 'none'])->default('none');
            $table->bigInteger('deposit_amount')->nullable();
            $table->date('deposit_date')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
        });

        // Pre-reserva del huésped (paso 1 del flujo); PK uuid en string.
        Schema::create('bookings', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('accommodation_id')->nullable();
            $table->integer('room_id')->nullable();
            $table->integer('tour_id')->nullable();
            $table->date('checkin')->nullable();
            $table->date('checkout')->nullable();
            $table->integer('adults')->nullable();
            $table->integer('childrens')->nullable();
            $table->string('name')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('inquiries', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            // En MySQL era char(36). Pasa a bigint porque el scope de tenancy
            // compara contra `accommodations.id` (`... IN (select accommodations.id)`)
            // y Postgres no compara varchar con bigint.
            $table->unsignedBigInteger('accommodation_id')->nullable()->index();
            $table->integer('channel_id')->nullable()->index();
            $table->string('name');
            $table->string('lastname');
            $table->string('email');
            $table->string('phone');
            $table->integer('adults');
            $table->integer('childrens');
            $table->date('checkin');
            $table->date('checkout')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['new', 'answered'])->default('new');
            $table->timestamps();

            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('guests');
    }
};
