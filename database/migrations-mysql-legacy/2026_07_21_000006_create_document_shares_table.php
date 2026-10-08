<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El consentimiento: qué documento de la bolsa se comparte con qué client.
     * **La existencia de la fila = está compartido.** El legajo nace privado; un
     * client ve un documento si y solo si hay un share para él (Regla C, §3).
     * Un archivo, muchos shares (Regla D). Ver `docs/documents-plan.md` §5.
     */
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shared_at')->useCurrent();
            // Fase 2: verificación FINA por (client, doc). La titular ("habilitado")
            // vive en `accommodation_client.verified_at` (Regla B).
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shares');
    }
};
