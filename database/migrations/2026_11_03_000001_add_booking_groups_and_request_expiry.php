<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor: una reserva temporal cubre varias habitaciones y una solicitud vence.
 * Cada habitación termina en su propia reserva, unidas por `booking_group_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->unsignedSmallInteger('booking_request_minutes')->default(60);
            $table->time('booking_attention_from')->nullable();
            $table->time('booking_attention_to')->nullable();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->uuid('booking_group_id')->nullable()->index();
            $table->timestamp('request_expires_at')->nullable()->index();
        });

        Schema::create('booking_hold_rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('booking_hold_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedBigInteger('rate_plan_id');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->json('nightly_rates');
            $table->bigInteger('total_amount');
            $table->uuid('reservation_id')->nullable();
            $table->timestamps();

            $table->foreign('booking_hold_id')->references('id')->on('booking_holds')->cascadeOnDelete();
            $table->index('room_id');
        });

        Schema::table('booking_holds', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->string('client_ip', 45)->nullable()->index();
        });

        DB::statement('
            INSERT INTO booking_hold_rooms
                (booking_hold_id, room_id, room_type_id, rate_plan_id, adults, children, nightly_rates, total_amount, reservation_id, created_at, updated_at)
            SELECT id, room_id, room_type_id, rate_plan_id, adults, children, nightly_rates, total_amount, reservation_id, created_at, updated_at
            FROM booking_holds
        ');
        DB::statement('UPDATE booking_holds SET submitted_at = updated_at WHERE reservation_id IS NOT NULL');

        Schema::table('booking_holds', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'expires_at']);
            $table->dropColumn(['room_id', 'room_type_id', 'rate_plan_id', 'nightly_rates', 'reservation_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_holds', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->unsignedBigInteger('rate_plan_id')->nullable();
            $table->json('nightly_rates')->nullable();
            $table->uuid('reservation_id')->nullable();
        });

        DB::statement('
            UPDATE booking_holds h SET
                room_id = r.room_id, room_type_id = r.room_type_id, rate_plan_id = r.rate_plan_id,
                nightly_rates = r.nightly_rates, reservation_id = r.reservation_id
            FROM (SELECT DISTINCT ON (booking_hold_id) * FROM booking_hold_rooms ORDER BY booking_hold_id, id) r
            WHERE r.booking_hold_id = h.id
        ');

        Schema::table('booking_holds', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'client_ip']);
            $table->index(['room_id', 'expires_at']);
        });

        Schema::dropIfExists('booking_hold_rooms');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['booking_group_id', 'request_expires_at']);
        });

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn(['booking_request_minutes', 'booking_attention_from', 'booking_attention_to']);
        });
    }
};
