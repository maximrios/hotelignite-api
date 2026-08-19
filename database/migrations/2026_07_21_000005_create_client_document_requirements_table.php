<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El checklist por client: qué tipos de documento exige cada uno (Regla A,
     * §2). La exigencia es del client, el documento es del prestador. En V1 se
     * siembra un checklist default y no hay UI de edición, pero la tabla existe
     * desde el día uno — un booleano `perfil_completo` no sobrevive a la primera
     * jurisdicción nueva. Ver `docs/documents-plan.md` §5.
     *
     * `entity_type` permite checklists distintos por tipo de prestador (hoy solo
     * `accommodation`).
     */
    public function up(): void
    {
        Schema::create('client_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('entity_type')->default('accommodation');
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->boolean('required')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'entity_type', 'document_type_id'], 'client_doc_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_document_requirements');
    }
};
