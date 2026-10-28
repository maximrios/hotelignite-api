<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hechos de recepción: check-in, check-out y no-show registrados. El estado
 * visible se sigue derivando (Reservation::derivedStatus), pero ahora "in-house"
 * exige check-in registrado y "finalizada" exige check-out.
 *
 * - `booked_checkout_date` / `booked_total_amount`: lo reservado antes de una
 *   salida anticipada, para poder deshacerla.
 * - `pre_checkin_housekeeping`: estado de la habitación antes del check-in, al
 *   que vuelve si se deshace.
 *
 * Legacy (status_id del catálogo `reservations_status`):
 * - 4 Check-in / 5 Check-out → `checked_in_at` (el `checkin` legacy o la fecha
 *   de llegada). 5 → además `checked_out_at`.
 * - 4 con la salida ya pasada: el legacy nunca registró la salida (459 en dev,
 *   todas viejas). Se cierran en la fecha de salida para que no queden como
 *   "salida pendiente" eterna.
 * - 6 No show: la migración anterior la dejó como cancelada con motivo
 *   "No show"; pasa a `no_show_at` y deja de estar cancelada.
 *
 * La exclusión de solapamientos ignora también los no-show: liberan la
 * habitación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->date('booked_checkout_date')->nullable();
            $table->bigInteger('booked_total_amount')->nullable();
            $table->string('pre_checkin_housekeeping', 3)->nullable();
        });

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_room_no_overlap');

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET checked_in_at = COALESCE(checkin, checkin_date::timestamp)
            WHERE status_id IN (4, 5) AND checked_in_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET checked_out_at = COALESCE(checkout, checkout_date::timestamp)
            WHERE status_id = 5 AND checked_out_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET checked_out_at = checkout_date::timestamp
            WHERE status_id = 4 AND checked_out_at IS NULL AND checkout_date < CURRENT_DATE
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET no_show_at = cancelled_at,
                cancelled_at = NULL,
                cancellation_reason = NULLIF(cancellation_reason, 'No show')
            WHERE status_id = 6 AND no_show_at IS NULL AND cancelled_at IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_room_no_overlap
            EXCLUDE USING gist (room_id WITH =, daterange(checkin_date, checkout_date) WITH &&)
            WHERE (
                room_id IS NOT NULL
                AND checkin_date IS NOT NULL
                AND checkout_date IS NOT NULL
                AND cancelled_at IS NULL
                AND no_show_at IS NULL
                AND deleted_at IS NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_room_no_overlap');

        // Los no-show vuelven a ser cancelaciones, como los dejó la migración anterior.
        DB::statement(<<<'SQL'
            UPDATE reservations
            SET cancelled_at = no_show_at,
                cancellation_reason = COALESCE(cancellation_reason, 'No show')
            WHERE no_show_at IS NOT NULL AND cancelled_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_room_no_overlap
            EXCLUDE USING gist (room_id WITH =, daterange(checkin_date, checkout_date) WITH &&)
            WHERE (
                room_id IS NOT NULL
                AND checkin_date IS NOT NULL
                AND checkout_date IS NOT NULL
                AND cancelled_at IS NULL
                AND deleted_at IS NULL
            )
        SQL);

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'checked_in_at', 'checked_out_at', 'no_show_at',
                'booked_checkout_date', 'booked_total_amount', 'pre_checkin_housekeeping',
            ]);
        });
    }
};
