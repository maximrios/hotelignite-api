<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API keys de clients externos (estilo Stripe). El secreto en claro se
     * muestra una sola vez al generarla; en la BD sólo vive el hash.
     * Formato de la credencial: `tk_live_<prefix>_<secret>`.
     *   - `prefix`: visible, sirve para localizar la fila sin exponer el secreto.
     *   - `key_hash`: sha256 del secreto completo.
     *   - `abilities`: json de permisos (ej. ["catalog:read","booking:create"]).
     *
     * También agrega el tier de rate limit al client (columna simple; se
     * grada a Plan-feature más adelante si hace falta).
     */
    public function up(): void
    {
        Schema::create('client_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name', 100)->nullable(); // etiqueta humana (ej. "prod", "staging")
            $table->string('prefix', 32)->unique();   // parte visible, ej. tk_live_a1b2c3d4
            $table->string('key_hash', 64);           // sha256 hex del secreto completo
            $table->json('abilities')->nullable();    // ["catalog:read","booking:create"]
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            // Tier de rate limit (requests/minuto). null => default de config.
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('rate_limit_per_minute');
        });

        Schema::dropIfExists('client_api_keys');
    }
};
