<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La API pública pasa a exigir `accommodation_client.verified_at` (el
 * "Habilitar" del client). Hasta hoy nadie lo escribía y se publicaba todo el
 * pivote `active`, así que sin este backfill la web de cada client se vacía.
 *
 * No se rellena todo igual, porque la palanca no es pareja (skill
 * `portal-asociados`, "El borrador no publica"):
 *
 * - Agencia / empresa: no tienen con qué exigir el vínculo. Se respeta lo que
 *   ya publicaban — todo alojamiento con dueño (TuriNorte vive de esto).
 * - Cámara / gobierno: publicar es justamente lo que da ser socio o adherido.
 *   Sólo quedan habilitados los que aceptaron el vínculo (`agreed_at`); el
 *   resto lo habilita el client a mano.
 *
 * Los borradores (sin `account_id`) no se habilitan en ningún caso.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE accommodation_client ac
               SET verified_at = NOW()
              FROM clients c, accommodations a
             WHERE c.id = ac.client_id
               AND a.id = ac.accommodation_id
               AND ac.status = 'active'
               AND ac.verified_at IS NULL
               AND a.account_id IS NOT NULL
               AND (c.type IN ('agency', 'company') OR ac.agreed_at IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: no hay forma de distinguir lo rellenado de lo que
        // el client habilitó después. La API vieja ignoraba la columna.
    }
};
