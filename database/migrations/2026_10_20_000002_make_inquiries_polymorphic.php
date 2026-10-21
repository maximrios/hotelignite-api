<?php

use App\Models\Accommodation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consultas polimórficas (docs/experiences-plan.md): una consulta es sobre un
 * alojamiento o sobre una experiencia.
 *
 * - `accommodation_id` pasa a `inquirable_type` + `inquirable_id`. El tipo guarda
 *   el nombre de clase completo, como `images` y `media`: registrar un morphMap
 *   global cambiaría cómo se guardan y buscan esas otras tablas.
 * - `checkin` pasa a nullable: en una experiencia es la fecha deseada, opcional.
 * - `client_id`: el portal por el que entró (null = panel / histórico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('inquirable_type', 100)->nullable()->after('id');
            $table->unsignedBigInteger('inquirable_id')->nullable()->after('inquirable_type');
            $table->foreignId('client_id')->nullable()->after('channel_id')->constrained('clients')->nullOnDelete();
            $table->date('checkin')->nullable()->change();

            $table->index(['inquirable_type', 'inquirable_id']);
        });

        DB::table('inquiries')
            ->whereNotNull('accommodation_id')
            ->update([
                'inquirable_type' => Accommodation::class,
                'inquirable_id' => DB::raw('accommodation_id'),
            ]);

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['accommodation_id']);
            $table->dropColumn('accommodation_id');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->unsignedBigInteger('accommodation_id')->nullable()->index()->after('id');
        });

        DB::table('inquiries')
            ->where('inquirable_type', Accommodation::class)
            ->update(['accommodation_id' => DB::raw('inquirable_id')]);

        // Las consultas de experiencias no tienen dónde ir en el esquema viejo.
        DB::table('inquiries')->where('inquirable_type', '!=', Accommodation::class)->delete();

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['inquirable_type', 'inquirable_id']);
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['inquirable_type', 'inquirable_id']);
        });
    }
};
