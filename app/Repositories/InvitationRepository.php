<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Mail\InvitationMail;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Repositories\Contracts\InvitationInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Lógica del flujo de invitaciones. Ver `.claude/skills/invitations.md`.
 *
 * Los tres desenlaces del accept se resuelven contra el estado del mundo, no
 * contra el tipo de invitación:
 *   register (A): no hay nada        → crea User + Account + Accommodation
 *   claim    (B): hay un borrador    → reclama el stub (setea account_id)
 *   consent  (C): ya está reclamado  → suma al padrón del client, con consentimiento
 */
class InvitationRepository implements InvitationInterface
{
    public function emit(int $clientId, User $createdBy, array $invitations): array
    {
        $days = (int) config('api.invitation_expiration_days', 30);
        $sent = [];
        $skipped = [];
        $seen = [];

        foreach ($invitations as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            // Validación de formato antes de encolar (§10, decisión 1): el
            // remitente es nuestro dominio; un pico de mails a direcciones basura
            // nos quema la reputación de envío. Defensa en profundidad —el
            // FormRequest ya valida, pero emit no confía en el llamador.
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                if ($email !== '') {
                    $skipped[] = $email;
                }

                continue;
            }

            // Un mail por casilla (§9): si el email se repite dentro del lote,
            // gana la primera fila —con sus nombres— y las demás se saltean.
            if (isset($seen[$email])) {
                $skipped[] = $email;

                continue;
            }
            $seen[$email] = true;

            // Dedupe anti-abuso: una sola invitación pendiente por (client, email).
            $pending = Invitation::where('client_id', $clientId)
                ->where('email', $email)
                ->pending()
                ->first();

            if ($pending) {
                $skipped[] = $email;

                continue;
            }

            $plain = Invitation::generateToken();

            $invitation = Invitation::create([
                'client_id' => $clientId,
                'accommodation_id' => null,
                'email' => $email,
                'contact_name' => $this->cleanName($row['contact_name'] ?? null),
                'accommodation_name' => $this->cleanName($row['accommodation_name'] ?? null),
                'token_hash' => Invitation::hashToken($plain),
                'expires_at' => now()->addDays($days),
                'created_by_user_id' => $createdBy->id,
            ]);

            Mail::to($email)->queue(new InvitationMail($invitation, $plain));
            $sent[] = $email;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    public function resend(Invitation $invitation, User $user): Invitation
    {
        // Rotamos el token en la misma fila: el anterior deja de servir (cambia el
        // hash) y sale uno nuevo. Evita chocar con el índice único parcial de
        // pendientes, y reabre la invitación si había sido declinada.
        $plain = Invitation::generateToken();

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($plain),
            'expires_at' => now()->addDays((int) config('api.invitation_expiration_days', 30)),
            'accepted_at' => null,
            'declined_at' => null,
        ])->save();

        Mail::to($invitation->email)->queue(new InvitationMail($invitation, $plain));

        return $invitation;
    }

    public function resolveByToken(string $token): Invitation
    {
        $invitation = Invitation::where('token_hash', Invitation::hashToken($token))->first();

        abort_if($invitation === null, 404, 'Invitación no encontrada.');

        return $invitation;
    }

    public function describe(Invitation $invitation): array
    {
        $invitation->loadMissing(['client', 'accommodation']);

        return [
            'case' => $this->resolveCase($invitation),
            'status' => $invitation->statusLabel(),
            'client_name' => $invitation->client?->name,
            'contact_name' => $invitation->contact_name,
            // El alojamiento reclamado gana; si no hay, el nombre propuesto al invitar.
            'accommodation_name' => $invitation->accommodation?->name ?? $invitation->accommodation_name,
            'expires_at' => $invitation->expires_at,
        ];
    }

    public function accept(Invitation $invitation, ?User $authUser, array $data): array
    {
        abort_unless($invitation->isUsable(), 410, 'La invitación ya no es válida.');

        return DB::transaction(function () use ($invitation, $authUser, $data) {
            // Lock para serializar dos accepts concurrentes del mismo token.
            $invitation = Invitation::whereKey($invitation->id)->lockForUpdate()->first();
            abort_unless($invitation->isUsable(), 410, 'La invitación ya no es válida.');

            $case = $this->resolveCase($invitation);
            $email = strtolower($invitation->email);

            // El rechazo que falta (§6): un email de platform/client no se
            // "convierte" en account. Es un desastre de privilegios.
            $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existing && ($existing->isPlatform() || $existing->isClient())) {
                abort(422, 'Ese email pertenece a un usuario interno y no puede reclamar un alojamiento.');
            }

            $user = match ($case) {
                'register' => $this->acceptRegister($invitation, $authUser, $existing, $data),
                'claim' => $this->acceptClaim($invitation, $authUser, $existing, $data),
                'consent' => $this->acceptConsent($invitation, $authUser),
            };

            $invitation->markAccepted($user);

            // Devolvemos el User (no un array plano): el controller lo necesita
            // para emitir un token de Sanctum y dejar al hotelero logueado, de
            // modo que la landing lo lleve directo a completar su ficha.
            return [
                'case' => $case,
                'accommodation_id' => $invitation->accommodation_id,
                'user' => $user,
            ];
        });
    }

    public function decline(Invitation $invitation, ?User $authUser): void
    {
        abort_unless($invitation->isUsable(), 410, 'La invitación ya no es válida.');

        // Solo el caso C (sumarse a un padrón) se rechaza. Un alta nueva no se
        // "rechaza": simplemente no se acepta.
        abort_if($this->resolveCase($invitation) !== 'consent', 422, 'Solo las invitaciones para sumarse a un padrón pueden rechazarse.');

        $this->assertAuthedAsInvitedEmail($authUser, $invitation);

        DB::transaction(function () use ($invitation) {
            $invitation->markDeclined();

            if ($invitation->client_id && $invitation->accommodation_id) {
                $client = Client::find($invitation->client_id);
                if ($client && $client->accommodations()->where('accommodations.id', $invitation->accommodation_id)->exists()) {
                    $client->accommodations()->updateExistingPivot($invitation->accommodation_id, ['status' => 'rejected']);
                }
            }
        });
    }

    public function associates(int $clientId): array
    {
        $client = Client::findOrFail($clientId);

        $associates = $client->accommodations()->get()->map(fn (Accommodation $a) => [
            'accommodation_id' => $a->id,
            'name' => $a->name,
            'slug' => $a->slug,
            'pivot_status' => $a->pivot->status,
            'verified_at' => $a->pivot->verified_at,
            'state' => $this->derivedState($a),
        ])->values();

        $pendingInvitations = Invitation::with('accommodation')
            ->where('client_id', $clientId)
            ->pending()
            ->get()
            ->map(fn (Invitation $i) => [
                'invitation_id' => $i->id,
                'email' => $i->email,
                'contact_name' => $i->contact_name,
                'accommodation_id' => $i->accommodation_id,
                'accommodation_name' => $i->accommodation?->name ?? $i->accommodation_name,
                'state' => 'invitado',
                'expires_at' => $i->expires_at,
            ])->values();

        return [
            'associates' => $associates,
            'pending_invitations' => $pendingInvitations,
        ];
    }

    /**
     * Caso derivado del estado del mundo (§3): register (A) / claim (B) / consent (C).
     */
    private function resolveCase(Invitation $invitation): string
    {
        if ($invitation->accommodation_id === null) {
            return 'register';
        }

        $accommodation = $invitation->accommodation;
        if ($accommodation === null) {
            return 'register';
        }

        return $accommodation->account_id === null ? 'claim' : 'consent';
    }

    /**
     * Caso A: no hay nada. Crea User + Account + Accommodation, o —si ya existe un
     * account con ese email— crea un alojamiento más bajo su cuenta.
     */
    private function acceptRegister(Invitation $invitation, ?User $authUser, ?User $existing, array $data): User
    {
        // Los nombres propuestos al invitar sirven de default; el hotelero pudo
        // haberlos corregido en el formulario, así que lo que llega en $data manda.
        $data['name'] = $data['name'] ?? $invitation->contact_name;
        $data['accommodation_name'] = $data['accommodation_name'] ?? $invitation->accommodation_name;

        if ($existing) {
            $this->assertAuthedAs($authUser, $existing);
            $accountId = $existing->account_id ?? $this->createAccountFor($existing)->id;
            $name = $data['accommodation_name'] ?? $existing->name;
            $user = $existing;
        } else {
            $this->requireRegistration($data, true);
            $account = $this->createBlankAccount($data['name'], $invitation->email);
            $user = $this->createAccountUser($data['name'], $invitation->email, $data['password'], $account->id);
            $accountId = $account->id;
            $name = $data['accommodation_name'];
        }

        $accommodation = $this->createAccommodation($accountId, $name, $invitation->email);

        // Apuntamos la invitación al alojamiento recién creado (trazabilidad).
        $invitation->forceFill(['accommodation_id' => $accommodation->id])->save();
        // Sin gate de staff (§8): el vínculo nace activo. Publica recién cuando
        // el client lo habilita (verified_at).
        $this->linkPivot($invitation, $accommodation->id, 'active');

        return $user;
    }

    /**
     * Caso B: hay un borrador (stub del staff). Lo reclama: setea account_id.
     */
    private function acceptClaim(Invitation $invitation, ?User $authUser, ?User $existing, array $data): User
    {
        $accommodation = $invitation->accommodation;

        if ($existing) {
            $this->assertAuthedAs($authUser, $existing);
            $accountId = $existing->account_id ?? $this->createAccountFor($existing)->id;
            $user = $existing;
        } else {
            // El stub ya tiene nombre: no exigimos accommodation_name.
            $this->requireRegistration($data, false);
            $account = $this->createBlankAccount($data['name'], $invitation->email);
            $user = $this->createAccountUser($data['name'], $invitation->email, $data['password'], $account->id);
            $accountId = $account->id;
        }

        $accommodation->forceFill(['account_id' => $accountId])->save();
        // Sin gate de staff (§8): el vínculo nace activo.
        $this->linkPivot($invitation, $accommodation->id, 'active');

        return $user;
    }

    /**
     * Caso C: ya está reclamado. No crea nada — pide sumarlo al padrón del client,
     * y exige estar logueado como ese email y ser dueño del alojamiento (§4, §6).
     */
    private function acceptConsent(Invitation $invitation, ?User $authUser): User
    {
        $this->assertAuthedAsInvitedEmail($authUser, $invitation);

        $accommodation = $invitation->accommodation;
        abort_unless(
            $authUser->isAccount() && $accommodation->account_id === $authUser->account_id,
            403,
            'Solo el dueño del alojamiento puede sumarlo a un padrón.'
        );

        // El consentimiento del hotelero ES la activación (§4): pivote activo.
        $this->linkPivot($invitation, $accommodation->id, 'active');

        return $authUser;
    }

    // --- Helpers -----------------------------------------------------------

    /** Normaliza un nombre opcional: recorta y convierte el vacío en null. */
    private function cleanName(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertAuthedAs(?User $authUser, User $target): void
    {
        abort_if(
            $authUser === null || $authUser->id !== $target->id,
            409,
            'Ya existe una cuenta con ese email. Iniciá sesión con ella para continuar.'
        );
    }

    private function assertAuthedAsInvitedEmail(?User $authUser, Invitation $invitation): void
    {
        abort_if(
            $authUser === null || strtolower($authUser->email) !== strtolower($invitation->email),
            409,
            'Iniciá sesión con el email de la invitación para continuar.'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireRegistration(array $data, bool $needsAccommodationName): void
    {
        abort_if(empty($data['name']) || empty($data['password']), 422, 'Nombre y contraseña son obligatorios.');
        abort_if($needsAccommodationName && empty($data['accommodation_name']), 422, 'El nombre del alojamiento es obligatorio.');
    }

    private function defaultPlanId(): ?int
    {
        return Plan::where('slug', config('api.default_plan_slug', 'free'))->value('id');
    }

    private function createBlankAccount(string $name, string $email): Account
    {
        return Account::create([
            'name' => $name,
            'email' => $email,
            'plan_id' => $this->defaultPlanId(),
            'active' => true,
            'token' => Str::random(40),
        ]);
    }

    private function createAccountFor(User $user): Account
    {
        $account = $this->createBlankAccount($user->name, $user->email);
        $user->forceFill(['account_id' => $account->id])->save();

        return $account;
    }

    private function createAccountUser(string $name, string $email, string $password, int $accountId): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $accountId,
        ]);
    }

    private function createAccommodation(int $accountId, string $name, ?string $email): Accommodation
    {
        // El slug se genera solo en el saving del modelo.
        return Accommodation::create([
            'account_id' => $accountId,
            'plan_id' => $this->defaultPlanId(),
            'name' => $name,
            'email' => $email,
        ]);
    }

    /**
     * Crea o actualiza el pivote accommodation ↔ client con el estado dado.
     * Si la invitación es del staff (client_id null) no hay pivote de client.
     */
    private function linkPivot(Invitation $invitation, int $accommodationId, string $status): void
    {
        if (! $invitation->client_id) {
            return;
        }

        $client = Client::find($invitation->client_id);
        if ($client === null) {
            return;
        }

        $attributes = ['status' => $status, 'invitation_id' => $invitation->id];

        if ($client->accommodations()->where('accommodations.id', $accommodationId)->exists()) {
            $client->accommodations()->updateExistingPivot($accommodationId, $attributes);
        } else {
            $client->accommodations()->attach($accommodationId, $attributes);
        }
    }

    /**
     * Estado del asociado para el padrón (§5). Sin gate de staff, el pivote nace
     * `active`, así que lo que distingue al publicable es el "Habilitar" del
     * client (`verified_at`):
     *   borrador   → sin dueño todavía
     *   reclamado  → tiene dueño pero el client no lo habilitó
     *   habilitado → el client lo habilitó (verified_at)
     * El estado intermedio `completo` (checklist) se agrega cuando exista la
     * completitud; hoy no se modela.
     */
    private function derivedState(Accommodation $accommodation): string
    {
        if ($accommodation->account_id === null) {
            return 'borrador';
        }

        return $accommodation->pivot->verified_at !== null ? 'habilitado' : 'reclamado';
    }
}
