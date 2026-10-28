<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parte de viajeros: el registro de cada persona alojada, no sólo del titular.
 *
 * - `reservation_guests`: todos los pasajeros de la estadía (titular incluido,
 *   `is_primary`), con la marca de menor. El titular sigue también en
 *   `reservations.guest_id` (lo usan rack, lista y lookup); se copia acá para
 *   que el registro se lea de un solo lugar.
 * - `guests.document_country`: país emisor del pasaporte (ISO-2).
 * - Vehículo en la reserva: es de la estadía, no de la persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('document_country', 2)->nullable();
        });

        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_id', 36);
            $table->integer('guest_id');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_minor')->default(false);
            $table->timestamps();

            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->cascadeOnDelete();
            $table->unique(['reservation_id', 'guest_id']);
            $table->index('guest_id');
        });

        DB::statement(<<<'SQL'
            INSERT INTO reservation_guests (reservation_id, guest_id, is_primary, is_minor, created_at, updated_at)
            SELECT r.id, r.guest_id, TRUE, FALSE, NOW(), NOW()
            FROM reservations r
            JOIN guests g ON g.id = r.guest_id
            ON CONFLICT DO NOTHING
        SQL);

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('vehicle_plate', 15)->nullable();
            $table->string('vehicle_description', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['vehicle_plate', 'vehicle_description']);
        });

        Schema::dropIfExists('reservation_guests');

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('document_country');
        });
    }
};
