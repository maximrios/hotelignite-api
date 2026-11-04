<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de reservas (`engine/`). `booking_mode` se activa a mano por alojamiento:
 * tener el plan no alcanza, porque publicar precios exige tarifas cargadas.
 * Los holds guardan el hash del token, nunca el token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('booking_mode', 20)->default('inquiry');
        });

        Schema::create('booking_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('accommodation_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedBigInteger('rate_plan_id');
            $table->date('checkin_date');
            $table->date('checkout_date');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->json('nightly_rates');
            $table->bigInteger('total_amount');
            $table->string('currency', 3);
            $table->timestamp('expires_at');
            $table->uuid('reservation_id')->nullable();
            $table->timestamps();

            $table->foreign('accommodation_id')->references('id')->on('accommodations')->cascadeOnDelete();
            $table->index(['room_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_holds');

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('booking_mode');
        });
    }
};
