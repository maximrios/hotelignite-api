<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte `reservations.id` de `int AUTO_INCREMENT` a `char(36)` (UUID).
 *
 * El modelo Reservation ya declara `HasUuids`, así que hoy hay un mismatch:
 * Eloquent genera un UUID string y lo intenta insertar en una columna int. Esta
 * migración alinea la columna con el modelo y con el resto del esquema
 * (inquiries/bookings ya usan PK string).
 *
 * Seguro sin reescribir tablas relacionadas: no existe ninguna FK entrante a
 * `reservations` (los FKs de la tabla salen hacia guests/rooms/status/channel).
 * Las filas legacy conservan su id numérico como string ('1', '2', ...); las
 * nuevas reservas reciben UUID. Idempotente vía information_schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        if ($this->idType() !== 'int') {
            return; // ya convertida (char/varchar) — no-op
        }

        // MODIFY reemplaza la definición completa de la columna, con lo que
        // elimina el AUTO_INCREMENT y conserva la PRIMARY KEY existente.
        DB::statement('ALTER TABLE `reservations` MODIFY `id` CHAR(36) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        if ($this->idType() === 'int') {
            return; // ya es int — no-op
        }

        // Reversión best-effort: sólo funciona si no se insertaron filas con UUID
        // (los ids no numéricos no pueden convertirse a int). Pensado para
        // rollback en desarrollo antes de generar reservas nuevas.
        DB::statement('ALTER TABLE `reservations` MODIFY `id` INT NOT NULL AUTO_INCREMENT');
    }

    private function idType(): ?string
    {
        $column = DB::selectOne(
            "SELECT DATA_TYPE AS data_type FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'reservations'
               AND column_name = 'id'"
        );

        return $column === null ? null : strtolower($column->data_type);
    }
};
