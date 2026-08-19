<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitaciones al flujo de alta de asociados. Ver `.claude/skills/invitations.md`.
     *
     * La invitación siempre es a un email; lo que pasa al aceptar depende del
     * estado del mundo (casos A/B/C), no del tipo de invitación.
     * `client_id` es nullable: cuando invita el staff no hay client.
     * `accommodation_id` es nullable: solo apunta a algo en los casos B y C.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            // Sin FK: `accommodations.id` es `bigint` con signo en la BD legacy,
            // incompatible con el `bigint unsigned` de `foreignId()`.
            $table->unsignedBigInteger('accommodation_id')->nullable()->index();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'email']);
        });

        // MySQL no soporta índices únicos parciales (`... WHERE`). Los emulamos con
        // columnas generadas que quedan NULL salvo cuando la invitación está
        // pendiente: MySQL trata cada NULL como distinto en un índice único, así
        // que dos filas ya resueltas (o de la misma casilla en otro estado) no
        // colisionan. Reglas de §5 de la skill:
        //   UNIQUE (client_id, email)            WHERE pending
        //   UNIQUE (client_id, accommodation_id) WHERE accommodation_id NOT NULL AND pending
        // Nota: con `client_id` NULL (invita el staff) el índice no restringe —
        // el NULL vuelve distinta cada fila — que es justo lo que queremos.
        DB::statement('
            ALTER TABLE invitations
            ADD COLUMN pending_email VARCHAR(255)
                GENERATED ALWAYS AS (CASE WHEN accepted_at IS NULL AND declined_at IS NULL THEN email END) STORED,
            ADD COLUMN pending_accommodation_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN accepted_at IS NULL AND declined_at IS NULL THEN accommodation_id END) STORED
        ');

        DB::statement('CREATE UNIQUE INDEX invitations_pending_email_unique ON invitations (client_id, pending_email)');
        DB::statement('CREATE UNIQUE INDEX invitations_pending_accommodation_unique ON invitations (client_id, pending_accommodation_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
