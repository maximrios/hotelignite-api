<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula un `Client` (principal de autenticación: api keys, rate limit, users,
 * scope vía `accommodation_client`) con su `Channel` (atribución comercial de
 * la demanda).
 *
 * Las dos tablas NO se fusionan: `channels` es un catálogo público
 * (`GET /api/v1/channels`) y `clients` guarda credenciales y tenancy. Este
 * campo es el puente: cuando Turinorte entra con su api key, la consulta o la
 * reserva que genera se estampa con `channel_id`, y el PMS agrupa todo por
 * channel sin saber que detrás hay un client.
 *
 * Nullable: un client puede existir sin canal propio (todavía no opera), y un
 * canal puede existir sin client (la web propia, el teléfono, Booking manual).
 *
 * Tipo `int` con signo —no `foreignId()`— porque `channels.id` viene del dump
 * legacy como `int`; la FK exige que ambas columnas coincidan exactamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'channel_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->integer('channel_id')->nullable()->after('type');
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'channel_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropColumn('channel_id');
        });
    }
};
