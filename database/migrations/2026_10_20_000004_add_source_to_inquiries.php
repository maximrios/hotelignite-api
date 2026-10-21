<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consultas que deja el asistente del alojamiento (docs/accommodation-mcp-plan.md,
 * fase 3).
 *
 * - `source`: por dónde entró (`web` | `portal` | `agent`). No se usa `channel_id`:
 *   esa FK apunta a los canales de venta (OTAs), otra cosa.
 * - `lastname` y `email` pasan a nullable: por WhatsApp el agente tiene el
 *   teléfono y un nombre, y pedirle el mail al huésped corta la conversación.
 *   Los portales y el formulario web los siguen exigiendo en su validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('source', 20)->default('web')->after('client_id');
            $table->string('lastname')->nullable()->change();
            $table->string('email')->nullable()->change();
        });

        // Lo histórico con portal atribuido entró por `client/v1`.
        DB::table('inquiries')->whereNotNull('client_id')->update(['source' => 'portal']);
    }

    public function down(): void
    {
        DB::table('inquiries')->whereNull('lastname')->update(['lastname' => '']);
        DB::table('inquiries')->whereNull('email')->update(['email' => '']);

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->string('lastname')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
