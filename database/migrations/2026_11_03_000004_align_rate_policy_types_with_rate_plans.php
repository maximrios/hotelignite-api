<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas de cancelación (`accommodation_rate_policies.rate_type`) usaban
 * flexible / semi_flexible / non_refundable, y las tarifas
 * (`rate_plans.cancellation`) flexible / moderate / strict / non_refundable: una
 * tarifa "moderada" o "estricta" no tenía regla que la describiera. Quedan los
 * cuatro de las tarifas; `semi_flexible` pasa a `moderate`.
 */
return new class extends Migration
{
    private const CHECK = 'accommodation_rate_policies_rate_type_check';

    public function up(): void
    {
        DB::statement('ALTER TABLE accommodation_rate_policies DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::table('accommodation_rate_policies')->where('rate_type', 'semi_flexible')->update(['rate_type' => 'moderate']);
        DB::statement('ALTER TABLE accommodation_rate_policies ADD CONSTRAINT '.self::CHECK
            ." CHECK (rate_type IN ('flexible', 'moderate', 'strict', 'non_refundable'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accommodation_rate_policies DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::table('accommodation_rate_policies')->where('rate_type', 'strict')->delete();
        DB::table('accommodation_rate_policies')->where('rate_type', 'moderate')->update(['rate_type' => 'semi_flexible']);
        DB::statement('ALTER TABLE accommodation_rate_policies ADD CONSTRAINT '.self::CHECK
            ." CHECK (rate_type IN ('flexible', 'semi_flexible', 'non_refundable'))");
    }
};
