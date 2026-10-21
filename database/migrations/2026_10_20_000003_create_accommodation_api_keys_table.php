<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys de un alojamiento (docs/accommodation-mcp-plan.md, fase 1). Las usa un
 * agente externo (Colmexa u otro cliente MCP) para contestar en nombre de UN
 * alojamiento. Mismo esquema que `client_api_keys`, pero el titular es la cuenta
 * hotelera y la key queda atada a un alojamiento concreto.
 *
 * `account_id` se guarda aparte del alojamiento a propósito: si el alojamiento
 * cambia de cuenta, la key deja de valer (el middleware compara los dos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->index()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('accommodation_id')->index()->constrained('accommodations')->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->string('prefix', 32)->unique();
            $table->string('key_hash', 64);
            $table->jsonb('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_api_keys');
    }
};
