<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitaciones de acceso: el staff da de alta un user sin contraseña y el user
 * la elige desde el link del email de bienvenida. Ver App\Models\UserInvitation.
 *
 * No es `invitations` (ese es el vínculo client ↔ alojamiento) ni
 * `password_reset_tokens` (Laravel borra el token al usarlo y no deja nada para
 * medir). Una fila por envío: los timestamps son la métrica de activación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sha256 del token. El recordatorio lo rota en la misma fila.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('sent_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            // Reenvío desde el CRM o contraseña cargada a mano: la fila deja de valer.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'accepted_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
