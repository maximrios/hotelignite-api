<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifas del PMS (modelo acordado con hotelería):
 *
 * - Planes a nivel hotel, asignados a uno o varios tipos (`rate_plan_room_type`).
 *   Un channel manager mapea tipo × plan; con el plan por tipo había que
 *   duplicarlo en cada uno. `rate_plans.room_type_id` queda por compatibilidad
 *   (endpoints legacy y planes viejos) y se copia a la pivote.
 * - Un plan base por hotel (`is_base`, la "tarifa de mostrador"): es el único
 *   con precio cargado día a día. Los demás derivan de él con un ajuste en %
 *   o en monto por noche (`adjustment_type` / `adjustment_value`, el monto en
 *   centavos), más su política de cancelación y régimen.
 * - `rates` pasa a ser precio por plan × tipo × día (`room_type_id`), con las
 *   restricciones del día: estadía mínima y venta cerrada (`stop_sell`).
 * - Ocupación por tipo: el precio base es para la ocupación estándar; se suma
 *   por adulto o menor adicional y se descuenta por uso single (centavos).
 * - Reserva: snapshot del precio por noche (`nightly_rates`) y si la tarifa se
 *   cargó a mano (`rate_is_manual`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('accommodation_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('rate_plans')->nullOnDelete();
            $table->boolean('is_base')->default(false);
            $table->enum('adjustment_type', ['percent', 'amount'])->nullable();
            $table->integer('adjustment_value')->nullable();
            $table->unsignedBigInteger('room_type_id')->nullable()->change();
            $table->dropUnique(['code']);
        });

        DB::statement(<<<'SQL'
            UPDATE rate_plans rp SET accommodation_id = rt.accommodation_id
            FROM room_types rt WHERE rt.id = rp.room_type_id AND rp.accommodation_id IS NULL
        SQL);

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->unique(['accommodation_id', 'code']);
        });

        Schema::create('rate_plan_room_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'room_type_id']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO rate_plan_room_type (rate_plan_id, room_type_id, created_at, updated_at)
            SELECT id, room_type_id, NOW(), NOW() FROM rate_plans WHERE room_type_id IS NOT NULL
        SQL);

        Schema::table('rates', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->constrained('room_types')->cascadeOnDelete();
            $table->boolean('stop_sell')->default(false);
            $table->dropUnique(['rate_plan_id', 'date']);
        });

        DB::statement(<<<'SQL'
            UPDATE rates r SET room_type_id = rp.room_type_id
            FROM rate_plans rp WHERE rp.id = r.rate_plan_id AND r.room_type_id IS NULL
        SQL);

        Schema::table('rates', function (Blueprint $table) {
            $table->unique(['rate_plan_id', 'room_type_id', 'date']);
            $table->index(['room_type_id', 'date']);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->bigInteger('extra_adult_price')->nullable();
            $table->bigInteger('extra_child_price')->nullable();
            $table->bigInteger('single_use_discount')->nullable();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->jsonb('nightly_rates')->nullable();
            $table->boolean('rate_is_manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['nightly_rates', 'rate_is_manual']);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['extra_adult_price', 'extra_child_price', 'single_use_discount']);
        });

        Schema::table('rates', function (Blueprint $table) {
            $table->dropUnique(['rate_plan_id', 'room_type_id', 'date']);
            $table->dropIndex(['room_type_id', 'date']);
            $table->dropConstrainedForeignId('room_type_id');
            $table->dropColumn('stop_sell');
            $table->unique(['rate_plan_id', 'date']);
        });

        Schema::dropIfExists('rate_plan_room_type');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropUnique(['accommodation_id', 'code']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['accommodation_id', 'is_base', 'adjustment_type', 'adjustment_value']);
            $table->unique(['code']);
        });
    }
};
