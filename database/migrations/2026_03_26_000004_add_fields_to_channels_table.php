<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // ----------------------------------------------------------------
            // RELACIÓN COMERCIAL — quién es el canal (no excluyente con connection_type)
            // Ejemplos: Despegar puede ser 'agency' pero con connection_type 'api'
            // ----------------------------------------------------------------
            $table->enum('business_type', [
                'direct',          // reserva directa: web propia, teléfono, walk-in
                'ota',             // OTA pura: Booking.com, Expedia, Airbnb
                'agency',          // agencia de viajes tradicional o híbrida
                'gds',             // Global Distribution System: Amadeus, Sabre, Galileo
                'corporate',       // empresa con tarifa corporativa
                'tour_operator',   // operador turístico / mayorista
                'metasearch',      // metabuscador: Google Hotels, Trivago, Kayak
            ])->default('direct')->after('name');

            // ----------------------------------------------------------------
            // INTEGRACIÓN TÉCNICA — cómo llegan las reservas (independiente del business_type)
            // Una agencia puede tener connection_type 'api' y ser a la vez ota en lo comercial
            // ----------------------------------------------------------------
            $table->enum('connection_type', [
                'manual',   // carga manual desde el PMS
                'api',      // channel manager / API en tiempo real
                'ical',     // sincronización iCalendar (Airbnb, VRBO)
                'gds',      // protocolo GDS (OTA_PING, HTNG)
                'email',    // confirmación por email / fax (canales legacy)
            ])->default('manual')->after('business_type');

            // Código corto para identificar el canal en el channel manager (ej. "BKG", "EXP")
            $table->string('code', 20)->nullable()->unique()->after('connection_type');

            // Comisión que cobra el canal (porcentaje, ej. 15.00 = 15%)
            $table->decimal('commission_rate', 5, 2)->nullable()->after('web');

            // Canal activo — deshabilitar evita nuevas reservas sin eliminar el historial
            $table->boolean('enabled')->default(true)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'connection_type', 'code', 'commission_rate', 'enabled']);
        });
    }
};
