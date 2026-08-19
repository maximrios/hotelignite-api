<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de la consulta. Espejo de `reservations.channel_id`: el PMS necesita
 * responder "de qué canal me entran las consultas" con el mismo group by que
 * usa para las reservas.
 *
 * Nullable porque el histórico no tiene origen conocido y porque el formulario
 * público puede no resolverlo. `null` se lee como "sin atribuir", no como
 * "directo" — si hiciera falta, el canal directo es una fila de `channels` con
 * `business_type = 'direct'`, no la ausencia del dato.
 *
 * Tipo `int` con signo para poder referenciar `channels.id` (legacy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inquiries', 'channel_id')) {
            return;
        }

        Schema::table('inquiries', function (Blueprint $table) {
            $table->integer('channel_id')->nullable()->after('accommodation_id');
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('inquiries', 'channel_id')) {
            return;
        }

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropColumn('channel_id');
        });
    }
};
