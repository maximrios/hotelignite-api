<?php

use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanFeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planes del PMS y uso de datos (decisiones del 2026-10-28, ver `hoteleria`):
 *
 * - `plans.coming_soon`: un plan que se muestra pero no se puede pedir
 *   (Premium hasta que exista el channel manager).
 * - `plan_change_requests`: el cambio de plan se solicita y lo confirma el
 *   equipo (todavía sin cobro integrado; Mercado Pago es el paso siguiente).
 *   Una sola solicitud pendiente por alojamiento.
 * - `legal_acceptances`: qué documento (T&C, privacidad, uso de datos
 *   agregados) aceptó cada usuario, en qué versión, cuándo y desde dónde.
 *   Se revoca hacia adelante (`revoked_at`), nunca se borra.
 * - Catálogo: se re-siembran features, planes y matriz (idempotente) y todo
 *   alojamiento sin plan pasa al Free — el estado "sin plan" desaparece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('coming_soon')->default(false);
        });

        Schema::create('plan_change_requests', function (Blueprint $table) {
            $table->id();
            $table->integer('accommodation_id')->index();
            $table->integer('from_plan_id')->nullable();
            $table->integer('to_plan_id');
            $table->string('billing_period', 10);
            // pending | approved | rejected | canceled
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            // terms | privacy | data_usage
            $table->string('document', 30);
            $table->string('version', 20);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'document', 'version']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX plan_change_requests_one_pending
                ON plan_change_requests (accommodation_id) WHERE status = 'pending'");
        }

        foreach ([FeatureSeeder::class, PlanSeeder::class, PlanFeatureSeeder::class] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        // Planes legacy sin features (Plan I/II/III del sistema viejo): se ocultan
        // del catálogo; sus alojamientos operan como Free hasta reasignarlos.
        DB::table('plans')->where('audience', 'accommodation')->whereIn('slug', ['plan-i', 'plan-ii', 'plan-iii'])
            ->update(['is_public' => false]);

        $free = DB::table('plans')->where('slug', 'free')->value('id');
        if ($free) {
            DB::table('accommodations')->whereNull('plan_id')->update(['plan_id' => $free]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('plan_change_requests');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('coming_soon');
        });
    }
};
