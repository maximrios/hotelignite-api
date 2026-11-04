<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los agentes IA activados por alojamiento. El motor es Colmexa (un tenant por
 * alojamiento, ver docs/ai-agents-plan.md); acá queda qué se activó, con qué
 * key y lo que Colmexa devolvió (la clave pública del widget).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            // Slug del catálogo (App\Agents\AgentCatalog): recepcionista, …
            $table->string('agent', 50);
            // active | inactive | error
            $table->string('status', 20)->default('inactive');
            // La key con la que Colmexa llama al MCP. Se rota en cada
            // sincronización: el secreto en claro no se guarda en ningún lado.
            $table->foreignId('api_key_id')->nullable()->constrained('accommodation_api_keys')->nullOnDelete();
            // Lo que elige el hotelero: saludo, orígenes del widget.
            $table->json('settings')->nullable();
            // Lo que devolvió Colmexa: public_key, snippet, script_url.
            $table->json('widget')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['accommodation_id', 'agent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_agents');
    }
};
