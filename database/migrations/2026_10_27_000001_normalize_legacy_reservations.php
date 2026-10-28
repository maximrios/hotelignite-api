<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Normaliza las reservas legacy del dump al esquema nuevo del PMS y protege la
 * base contra reservas solapadas en una misma habitación (lo necesita el rack).
 *
 * 1. `room_type_id`: tipo de habitación VENDIDO. Puede diferir del tipo de la
 *    habitación asignada (upgrade/downgrade) y es lo que necesita la fila
 *    "Sin asignar" para agrupar las reservas que todavía no tienen habitación.
 * 2. Fechas: `arrival`/`departure` → `checkin_date`/`checkout_date`. Las 33 filas
 *    con `departure <= arrival` toman `arrival + nights` (mínimo una noche).
 * 3. Montos: `rate`/`rate_total` decimales → `rate_amount`/`total_amount` en
 *    centavos. Con `rate_total` en 0 se usa `rate × noches`.
 * 4. Habitación: el legacy la guardaba en `reservation_rooms`. Se copia sólo si
 *    la reserva tiene UNA habitación y es del mismo alojamiento; las multi-room
 *    (62) quedan sin asignar, el rack modela una habitación por reserva.
 * 5. Estado: Cancelada (3) y No show (6) → `cancelled_at`, que es de lo que se
 *    deriva el estado.
 * 6. Solapamientos legacy (14 en dev): se desasigna la habitación de la reserva
 *    más nueva de cada par, para poder crear la exclusión.
 * 7. EXCLUDE gist: dos reservas vivas no pueden compartir habitación y noches.
 *
 * Los pasos 2-6 sólo tocan columnas que estaban en NULL: reejecutarla no pisa
 * datos cargados por el PMS. El down() deshace el esquema, no los datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->integer('room_type_id')->nullable()->index()->after('room_id');
        });

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET checkin_date = arrival,
                checkout_date = CASE
                    WHEN departure > arrival THEN departure
                    ELSE arrival + GREATEST(COALESCE(nights, 1), 1)
                END
            WHERE checkin_date IS NULL AND arrival IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET rate_amount = CASE WHEN rate > 0 THEN ROUND(rate * 100) END,
                total_amount = CASE
                    WHEN rate_total > 0 THEN ROUND(rate_total * 100)
                    WHEN rate > 0 THEN ROUND(rate * 100) * (checkout_date - checkin_date)
                END
            WHERE total_amount IS NULL AND rate_amount IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations r
            SET room_id = s.room_id
            FROM (
                SELECT rr.reservation_id, MIN(rr.room_id) AS room_id
                FROM reservation_rooms rr
                WHERE rr.deleted_at IS NULL
                GROUP BY rr.reservation_id
                HAVING COUNT(DISTINCT rr.room_id) = 1
            ) s
            JOIN rooms ro ON ro.id = s.room_id
            WHERE s.reservation_id = r.id
              AND ro.accommodation_id = r.accommodation_id
              AND r.room_id IS NULL
        SQL);

        // Tipo vendido: el de reservation_rooms si es único; si no, el de la
        // habitación asignada.
        DB::statement(<<<'SQL'
            UPDATE reservations r
            SET room_type_id = s.room_type_id
            FROM (
                SELECT reservation_id, MIN(room_type_id) AS room_type_id
                FROM reservation_rooms
                WHERE deleted_at IS NULL AND room_type_id > 0
                GROUP BY reservation_id
                HAVING COUNT(DISTINCT room_type_id) = 1
            ) s
            WHERE s.reservation_id = r.id AND r.room_type_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations r
            SET room_type_id = ro.room_type_id
            FROM rooms ro
            WHERE ro.id = r.room_id AND r.room_type_id IS NULL AND ro.room_type_id > 0
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations
            SET cancelled_at = COALESCE(modified, created),
                cancellation_reason = CASE WHEN status_id = 6 THEN 'No show' ELSE cancellation_reason END
            WHERE status_id IN (3, 6) AND cancelled_at IS NULL
        SQL);

        $this->unassignOverlaps();

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Sin fechas, daterange(NULL, NULL) es infinito y chocaría con todo.
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
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_room_no_overlap');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('room_type_id');
        });
    }

    /**
     * Desasigna, de cada par solapado, la reserva creada después. Itera porque
     * una reserva puede pisarse con varias.
     */
    private function unassignOverlaps(): void
    {
        $total = 0;

        do {
            $ids = DB::select(<<<'SQL'
                SELECT DISTINCT b.id
                FROM reservations a
                JOIN reservations b
                  ON a.room_id = b.room_id
                 AND a.id <> b.id
                 AND (a.created, a.id) < (b.created, b.id)
                WHERE a.cancelled_at IS NULL AND b.cancelled_at IS NULL
                  AND a.deleted_at IS NULL AND b.deleted_at IS NULL
                  AND daterange(a.checkin_date, a.checkout_date) && daterange(b.checkin_date, b.checkout_date)
                LIMIT 1
            SQL);

            foreach ($ids as $row) {
                DB::table('reservations')->where('id', $row->id)->update(['room_id' => null]);
            }

            $total += count($ids);
        } while ($ids !== []);

        if ($total > 0) {
            Log::info("normalize_legacy_reservations: {$total} reservas solapadas quedaron sin habitación");
        }
    }
};
