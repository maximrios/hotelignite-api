<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reservations_status` es el catálogo que mapea `App\Models\Status` y al que
 * apunta `reservations.status_id`. Existe en el dump legacy pero ninguna
 * migración la creaba: en una base levantada desde cero cualquier consulta de
 * estado de reserva explotaba.
 *
 * Idempotente en los dos sentidos: no toca la tabla si ya está, y las filas se
 * insertan con `updateOrInsert` por `id`, así que correr esto sobre dev o
 * producción —que ya las tienen— no cambia nada.
 *
 * El esquema es el real de la base legacy (utf8mb3, `id` int, sin timestamps
 * poblados), no una versión "prolija": `status_id` es un `tinyint` del snapshot
 * de `reservations` y el catálogo tiene que seguir siendo comparable con él.
 */
return new class extends Migration
{
    /** Los seis estados tal como están hoy en la base de desarrollo. */
    private const ESTADOS = [
        [1, 'Pendiente', 'pending', 'Estas reservas estan pendiente de confirmacion'],
        [2, 'Confirmada', 'confirmed', 'Estas reservas fueron confirmadas porque se recibio un pago parcial sobre el total de la reserva'],
        [3, 'Cancelada', 'cancelled', 'Esta reserva fue cancelada por el huesped o por el hotel al no registrar un pago parcial'],
        [4, 'Check-in', 'checkin', 'Esta reserva tiene checkin realizado por el huesped'],
        [5, 'Check-out', 'checkout', 'Esta reserva esta cerrada, el huesped hizo checkout'],
        [6, 'No show', 'noshow', 'Esta reserva fue marcada como NO SHOW el huesped no se presento en el hotel y no dio aviso'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('reservations_status')) {
            DB::unprepared(<<<'SQL'
CREATE TABLE `reservations_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci DEFAULT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci
SQL);
        }

        foreach (self::ESTADOS as [$id, $name, $slug, $description]) {
            DB::table('reservations_status')->updateOrInsert(
                ['id' => $id],
                ['name' => $name, 'slug' => $slug, 'description' => $description],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations_status');
    }
};
