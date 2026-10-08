<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repone la FK `reservations.rate_plan_id → rate_plans.id`, la única diferencia
 * de foreign keys entre la base de desarrollo y una levantada desde cero.
 *
 * Se perdía porque el snapshot de `reservations` ya crea la columna, así que la
 * guarda `if (! Schema::hasColumn(...))` de `2026_05_21_000001` se saltea el
 * bloque — y con él el `->constrained()->nullOnDelete()`.
 *
 * Es no-op en dev y en producción, que ya la tienen.
 */
return new class extends Migration
{
    private const FK = 'reservations_rate_plan_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('rate_plans') || ! Schema::hasColumn('reservations', 'rate_plan_id')) {
            return;
        }

        if ($this->foreignKeyExists()) {
            return;
        }

        // Las filas viejas pueden apuntar a un rate plan inexistente: la FK no
        // se puede crear con datos que la violan, y borrar reservas para poder
        // agregar una FK sería peor que no tenerla.
        DB::table('reservations')
            ->whereNotNull('rate_plan_id')
            ->whereNotIn('rate_plan_id', fn ($q) => $q->select('id')->from('rate_plans'))
            ->update(['rate_plan_id' => null]);

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('rate_plan_id', self::FK)
                ->references('id')->on('rate_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->foreignKeyExists()) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(self::FK);
        });
    }

    private function foreignKeyExists(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'reservations')
            ->where('CONSTRAINT_NAME', self::FK)
            ->exists();
    }
};
