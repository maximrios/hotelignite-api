<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Mail\UserWelcomeMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Repositories\Contracts\UserInvitationInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Invitaciones de acceso de users dados de alta por el staff. Ver
 * App\Models\UserInvitation.
 */
class UserInvitationRepository implements UserInvitationInterface
{
    public function issue(User $user, ?User $createdBy): UserInvitation
    {
        abort_unless($user->isClient(), 422, 'Por ahora la invitación por email es sólo para usuarios de clients.');

        return DB::transaction(function () use ($user, $createdBy) {
            $this->revokePending($user);

            $plain = UserInvitation::generateToken();

            $invitation = UserInvitation::create([
                'user_id' => $user->id,
                'token_hash' => UserInvitation::hashToken($plain),
                'sent_at' => now(),
                'expires_at' => now()->addDays((int) config('api.user_invitation_expiration_days', 7)),
                'created_by_user_id' => $createdBy?->id,
            ]);

            Mail::to($user->email)->queue(new UserWelcomeMail($invitation, $plain));

            Log::info('users.invitation.issued', [
                'user_id' => $user->id,
                'invitation_id' => $invitation->id,
                'actor_id' => $createdBy?->id,
            ]);

            return $invitation;
        });
    }

    public function revokePending(User $user): void
    {
        $user->invitations()->pending()->update(['revoked_at' => now()]);
    }

    public function resolveByToken(string $token): UserInvitation
    {
        $invitation = UserInvitation::where('token_hash', UserInvitation::hashToken($token))->first();

        // `User` tiene borrado lógico y la FK sólo cascadea el borrado físico:
        // la invitación de un user dado de baja sigue en la tabla, pero su
        // relación `user` vuelve null. Para el link es lo mismo que no existir.
        abort_if($invitation === null || $invitation->user === null, 404, 'El enlace no es válido.');

        return $invitation;
    }

    public function describe(UserInvitation $invitation): array
    {
        $status = $invitation->status();

        // Fuera de vigencia no hace falta mostrar a quién pertenece el link.
        if (! $invitation->isUsable()) {
            return ['status' => $status];
        }

        $user = $invitation->user()->with('client')->first();

        return [
            'status' => $status,
            'name' => $user->name,
            'email' => $user->email,
            'client_name' => $user->client?->name,
            'expires_at' => $invitation->expires_at,
        ];
    }

    public function markOpened(UserInvitation $invitation): void
    {
        if ($invitation->isUsable() && $invitation->opened_at === null) {
            $invitation->forceFill(['opened_at' => now()])->save();
        }
    }

    public function accept(UserInvitation $invitation, string $password): User
    {
        abort_unless($invitation->isUsable(), 410, 'El enlace ya no es válido.');

        return DB::transaction(function () use ($invitation, $password) {
            // Lock para serializar dos envíos concurrentes del mismo token.
            $invitation = UserInvitation::whereKey($invitation->id)->lockForUpdate()->first();
            abort_unless($invitation->isUsable(), 410, 'El enlace ya no es válido.');

            $user = $invitation->user;

            $user->forceFill([
                'password' => Hash::make($password),
                // Usar el link prueba que la casilla es suya.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $invitation->forceFill([
                'accepted_at' => now(),
                'opened_at' => $invitation->opened_at ?? now(),
            ])->save();

            Log::info('users.invitation.accepted', [
                'user_id' => $user->id,
                'invitation_id' => $invitation->id,
            ]);

            return $user;
        });
    }

    public function sendReminders(): int
    {
        $days = (int) config('api.user_invitation_reminder_days', 3);
        $sent = 0;

        UserInvitation::pending()
            ->whereNull('reminded_at')
            ->where('sent_at', '<=', now()->subDays($days))
            ->where('expires_at', '>', now())
            ->with('user')
            ->each(function (UserInvitation $invitation) use (&$sent) {
                if ($invitation->user === null) {
                    return;
                }

                $plain = UserInvitation::generateToken();

                $invitation->forceFill([
                    'token_hash' => UserInvitation::hashToken($plain),
                    'reminded_at' => now(),
                ])->save();

                Mail::to($invitation->user->email)->queue(new UserWelcomeMail($invitation, $plain, reminder: true));
                $sent++;
            });

        return $sent;
    }
}
