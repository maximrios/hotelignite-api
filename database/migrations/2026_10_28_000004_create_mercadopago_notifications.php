<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks de Mercado Pago (firma HMAC validada). Se guarda cada aviso, se
 * responde 200 y se procesa después; lo que falla queda con `processed_at` null
 * y lo reintenta `mercadopago:retry-notifications`.
 *
 * Una fila por (topic, resource_id): el mismo preapproval avisa varias veces
 * (pending → authorized → paused…) y el procesamiento siempre relee el estado
 * actual en Mercado Pago, así que un aviso nuevo sólo vuelve a marcarla pendiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercadopago_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 60);
            $table->string('resource_id', 64);
            $table->string('request_id', 100)->nullable();
            $table->jsonb('payload')->nullable();
            $table->unsignedInteger('received_count')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['topic', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercadopago_notifications');
    }
};
