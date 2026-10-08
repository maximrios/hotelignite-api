<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La bolsa (legajo) del prestador. Polimórfica como `images`
     * (`morphs('documentable')`) para que mañana entren `Restaurant` y
     * `TourOperator` sin tocar el esquema. Un doc puede colgar de `Accommodation`
     * o de `Account` (fiscal es de la empresa). Ver `docs/documents-plan.md` §4-5.
     *
     * OJO: el archivo NO va a una URL pública (§7). Guardamos `disk` + `path` de
     * un bucket privado; el binario se sirve por un endpoint que valida el share
     * en cada request. `url` (como en `images`) sería una revocación de mentira.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->string('title');
            $table->string('disk')->default('documents');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Fase 2 (vencimientos): nullable desde ya para no rehacer la tabla.
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            // Borrar un doc no debe romper el histórico de shares.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
