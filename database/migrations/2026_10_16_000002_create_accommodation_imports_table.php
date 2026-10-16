<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procedencia de los alojamientos cargados desde padrones externos (registro
 * provincial de Salta, etc.). Una fila por registro de la fuente:
 *
 * - `(source, source_key)` único: re-correr la importación no duplica.
 * - `payload`: la fila original tal cual vino — clase y categoría oficiales
 *   (Residencial A/B, "Inmuebles - Habitación") que no tienen columna propia.
 * - `changes`: qué campos vacíos se completaron en un alojamiento existente.
 * - `conflicts`: campos donde la fuente y la base difieren y se dejó la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->string('source', 100);
            $table->string('source_key', 100);
            $table->enum('action', ['created', 'enriched']);
            $table->jsonb('payload');
            $table->jsonb('changes')->nullable();
            $table->jsonb('conflicts')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_imports');
    }
};
