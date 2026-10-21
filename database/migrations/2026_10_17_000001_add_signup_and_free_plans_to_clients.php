<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de agencias y planes free.
 *
 * - `clients.origin`: `staff` (alta desde el CRM) o `signup` (se registró sola en
 *   el portal). Sólo a los de `signup` se les exige confirmar el email y la
 *   verificación del staff: a los otros los cargó alguien que ya los conoce.
 * - `clients.verified_at`: el staff revisó la agencia (CUIT y legajo de agencia
 *   de viajes, Ley 18.829). Sin eso no puede invitar hoteleros.
 * - `plans.client_types`: a qué tipos de client se ofrece el plan (null = a
 *   todos). Reemplaza deducirlo de la feature `sales`, que dejó de servir cuando
 *   la agencia pasó a tener planes con y sin venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('origin', 20)->default('staff')->after('type');
            $table->string('license_number', 50)->nullable()->after('iva_condition');
            $table->timestamp('verified_at')->nullable()->after('license_number');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->jsonb('client_types')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('client_types');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn(['origin', 'license_number', 'verified_at']);
        });
    }
};
