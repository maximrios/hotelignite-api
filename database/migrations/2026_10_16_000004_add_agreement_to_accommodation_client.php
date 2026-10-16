<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Padrón con vínculo: estar en el padrón de un client no es lo mismo que haber
 * aceptado el vínculo con él (convenio con la agencia, socio de la cámara,
 * adhesión al padrón del municipio).
 *
 * - `accommodation_client.agreed_at`: el hotelero aceptó. Lo escribe el accept de
 *   la invitación; asociar desde el CRM deja el pivote sin vínculo.
 * - El único de invitaciones pendientes por `(client_id, email)` pasa a valer sólo
 *   para las invitaciones sin alojamiento (alta por email). Con alojamiento, el
 *   que ya protege es `(client_id, pending_accommodation_id)`: un dueño con tres
 *   alojamientos en el padrón de una agencia tiene que poder recibir las tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_client', function (Blueprint $table) {
            $table->timestamp('agreed_at')->nullable()->after('status');
        });

        // Los vínculos que ya nacieron de una invitación aceptada son vínculos aceptados.
        DB::statement(<<<'SQL'
            UPDATE accommodation_client ac
               SET agreed_at = i.accepted_at
              FROM invitations i
             WHERE i.id = ac.invitation_id
               AND i.accepted_at IS NOT NULL
        SQL);

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropUnique('invitations_pending_email_unique');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX invitations_pending_email_unique
                ON invitations (client_id, pending_email)
             WHERE accommodation_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invitations_pending_email_unique');

        Schema::table('invitations', function (Blueprint $table) {
            $table->unique(['client_id', 'pending_email'], 'invitations_pending_email_unique');
        });

        Schema::table('accommodation_client', function (Blueprint $table) {
            $table->dropColumn('agreed_at');
        });
    }
};
