<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El client conoce a sus prestadores: al invitar puede aportar el nombre de
     * la persona a cargo y del establecimiento. Ambos opcionales — el email es
     * lo único obligatorio. Sirven para:
     *   - personalizar el mail (sube la aceptación, que gatea todo el producto),
     *   - que el padrón muestre "Hotel X" en vez de una pared de emails anónimos,
     *   - pre-llenar el alta del alojamiento cuando el hotelero acepta (caso A).
     *
     * Aditiva y no destructiva a propósito: agrega dos columnas nullable y nada
     * más. No toca las columnas generadas (`pending_email`,
     * `pending_accommodation_id`) ni los índices únicos parciales de la migración
     * original, así que no requiere rollback de aquélla ni pierde datos.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('contact_name')->nullable();
            $table->string('accommodation_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'accommodation_name']);
        });
    }
};
