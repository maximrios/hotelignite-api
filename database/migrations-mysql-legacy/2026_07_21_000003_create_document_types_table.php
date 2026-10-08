<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de tipos de documento (habilitación municipal, seguro, bomberos,
     * inscripción fiscal, etc.). Es dato, no `if` por slug en el código — mismo
     * criterio que `features`. Ver `docs/documents-plan.md` §5.
     *
     * - `entity_type`: a qué prestador aplica el tipo (null = universal). Hoy solo
     *   `accommodation`; deja lugar para `restaurant` / `tour_operator`.
     * - `owner_level`: dónde cuelga naturalmente — `account` (fiscal, de la empresa)
     *   o `entity` (habilitación, por propiedad).
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('entity_type')->nullable();
            $table->enum('owner_level', ['account', 'entity'])->default('entity');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
