<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conexión de un alojamiento con un canal. `channels` es un catálogo global
 * (Booking, la web propia, el teléfono, una agencia); este pivote es el que
 * responde "a qué canales estoy conectado YO", que es lo que lista el menú
 * Channels del PMS.
 *
 * - `enabled`: el hotel pausa el canal sin perder el histórico de reservas.
 * - `commission_rate`: cada hotel negocia su propia comisión, así que este
 *   valor pisa al de `channels.commission_rate`, que queda como default del
 *   catálogo. `null` = usar el default.
 * - `external_code`: el identificador del alojamiento DENTRO del canal (ej. su
 *   hotel id en Booking). Distinto de `channels.code`, que identifica al canal.
 *
 * Sin FK sobre `accommodation_id`: `accommodations.id` es `bigint` con signo en
 * la BD legacy, incompatible con el `bigint unsigned` de `foreignId()` — mismo
 * criterio que `accommodation_client`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id')->index();
            // `int` con signo para poder referenciar `channels.id` (legacy).
            $table->integer('channel_id');
            $table->boolean('enabled')->default(true);
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->string('external_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['accommodation_id', 'channel_id']);
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_channels');
    }
};
