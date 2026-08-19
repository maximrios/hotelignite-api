<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enriquece el pivote `accommodation_client` para el flujo de asociados.
     *
     * - `status`: el vínculo nace `active` al aceptar. NO hay gate de staff
     *   (se eliminó, §8). El único gate de publicación es el "Habilitar" del
     *   client (`verified_at`). `rejected` lo setea el decline del caso C.
     * - `verified_at`: el "Habilitar" del client — gate de publicación (§8).
     *   La "verificación" es del pivote, no del alojamiento (Regla B, §2), así
     *   que cada client le pone su propio significado (habilitación, convenio…).
     * - `invitation_id`: trazabilidad del origen del vínculo.
     */
    public function up(): void
    {
        Schema::table('accommodation_client', function (Blueprint $table) {
            $table->enum('status', ['active', 'rejected'])->default('active')->after('client_id');
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->after('verified_by_user_id')->constrained('invitations')->nullOnDelete();
        });

        // Las filas que ya existían son vínculos legítimos (flujo F4 de clients
        // B2B) y deben permanecer visibles. El default `active` ya se lo aplica
        // MySQL a las filas preexistentes al agregar la columna; no hace falta
        // backfill explícito.
    }

    public function down(): void
    {
        Schema::table('accommodation_client', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invitation_id');
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn(['status', 'verified_at']);
        });
    }
};
