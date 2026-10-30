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
use App\Repositories\Contracts\DuesInterface;
use App\Repositories\Contracts\InvitationInterface;
use App\Support\AssociateType;
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
    /**
     * Estado del asociado en el padrón, derivado (no se guarda):
     *   rechazado   → el hotelero dijo que no
     *   vinculado   → aceptó el vínculo (convenio, socio, adhesión)
     *   invitado    → hay una invitación vigente sin responder
     *   borrador    → sin dueño en HotelIgnite
     *   sin_vinculo → tiene dueño pero no aceptó el vínculo con este client
     * Publicar es otro eje (`verified_at`, el "Habilitar" del client).
     */
    public const STATES = ['borrador', 'invitado', 'sin_vinculo', 'vinculado', 'rechazado'];

    public function __construct(private DuesInterface $dues) {}

    private const STATE_SQL = <<<'SQL'
        CASE
            WHEN ac.status = 'rejected' THEN 'rechazado'
            WHEN ac.agreed_at IS NOT NULL THEN 'vinculado'
            WHEN EXISTS (
                SELECT 1 FROM invitations pi
                 WHERE pi.client_id = ac.client_id
                   AND pi.accommodation_id = ac.accommodation_id
                   AND pi.accepted_at IS NULL AND pi.declined_at IS NULL
                   AND pi.expires_at > NOW()
            ) THEN 'invitado'
            WHEN a.account_id IS NULL THEN 'borrador'
            ELSE 'sin_vinculo'
        END
        SQL;

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
        // Una aceptada no se reabre: el vínculo ya existe. Una rechazada respeta el
        // mismo plazo que invitar de nuevo, o "Reenviar" saltearía el anti-spam.
        abort_if($invitation->accepted_at !== null, 422, 'La invitación ya fue aceptada.');
        $cooldown = (int) config('api.invitation_decline_cooldown_days', 90);
        abort_if(
            $invitation->declined_at !== null && $invitation->declined_at->gt(now()->subDays($cooldown)),
            422,
            'El alojamiento rechazó la invitación hace poco. Vas a poder volver a invitarlo más adelante.'
        );

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

        $accommodationName = $invitation->accommodation?->name ?? $invitation->accommodation_name;

        return [
            'case' => $this->resolveCase($invitation),
            'status' => $invitation->statusLabel(),
            // Si el email invitado ya tiene usuario. Decide si la landing pide
            // registrarse o iniciar sesión: un borrador (claim) lo puede tomar
            // alguien sin cuenta. No expone el email; sólo lo ve quien tiene el
            // token, que es quien recibió el mail.
            'has_account' => User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists(),
            'client_name' => $invitation->client?->name,
            'client_type' => $invitation->client?->type,
            // Doble marca: el hotelero aterriza viendo lo mismo que vio en el mail.
            'client_brand' => $invitation->client?->brand(),
            // La frase que cambia según quién invita ("te propone un convenio",
            // "como socio de la cámara"...). Null si invita el staff.
            'pitch' => $invitation->client?->invitationPitch($accommodationName),
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

        $this->markDeclinedWithPivot($invitation);
    }

    public function emitForAccommodations(int $clientId, User $createdBy, array $accommodationIds, ?string $email = null): array
    {
        $client = Client::findOrFail($clientId);
        $days = (int) config('api.invitation_expiration_days', 30);
        $cooldown = (int) config('api.invitation_decline_cooldown_days', 90);
        $override = $email !== null ? strtolower(trim($email)) : null;
        $sent = [];
        $skipped = [];

        $accommodations = $client->accommodations()
            ->whereIn('accommodations.id', array_unique(array_map('intval', $accommodationIds)))
            ->get()
            ->keyBy('id');

        foreach (array_unique(array_map('intval', $accommodationIds)) as $id) {
            $skip = function (string $reason) use (&$skipped, $id): void {
                $skipped[] = ['accommodation_id' => $id, 'reason' => $reason];
            };

            $accommodation = $accommodations->get($id);
            if ($accommodation === null) {
                $skip('not_in_padron');

                continue;
            }
            if ($accommodation->pivot->agreed_at !== null) {
                $skip('already_agreed');

                continue;
            }

            $previous = Invitation::where('client_id', $clientId)
                ->where('accommodation_id', $id)
                ->latest('id')
                ->first();

            if ($previous?->isUsable()) {
                $skip('already_invited');

                continue;
            }
            // Anti-spam: si el hotelero dijo que no, el client espera antes de volver a pedir.
            if ($previous?->declined_at !== null && $previous->declined_at->gt(now()->subDays($cooldown))) {
                $skip('declined_recently');

                continue;
            }

            $to = $this->recipientFor($accommodation, $override);
            if ($to === null) {
                $skip('no_email');

                continue;
            }

            $plain = Invitation::generateToken();
            $attributes = [
                'email' => $to,
                'accommodation_name' => $accommodation->name,
                'token_hash' => Invitation::hashToken($plain),
                'expires_at' => now()->addDays($days),
                'accepted_at' => null,
                'accepted_by_user_id' => null,
                'declined_at' => null,
                'created_by_user_id' => $createdBy->id,
            ];

            // Una vencida sigue "pendiente" para el índice único: se reusa la fila.
            $invitation = $previous !== null && $previous->isPending()
                ? tap($previous)->forceFill($attributes)->save()
                : Invitation::create($attributes + ['client_id' => $clientId, 'accommodation_id' => $id]);

            if ($accommodation->pivot->invitation_id !== $invitation->id) {
                $client->accommodations()->updateExistingPivot($id, ['invitation_id' => $invitation->id]);
            }

            Mail::to($to)->queue(new InvitationMail($invitation, $plain));
            $sent[] = $id;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    public function incomingFor(User $owner): array
    {
        if (! $owner->isAccount() || $owner->account_id === null) {
            return [];
        }

        return Invitation::with(['client', 'accommodation'])
            ->whereHas('accommodation', fn ($q) => $q->where('account_id', $owner->account_id))
            ->whereNotNull('client_id')
            ->pending()
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get()
            ->map(fn (Invitation $i) => [
                'id' => $i->id,
                'client_name' => $i->client?->name,
                'client_type' => $i->client?->type,
                'client_brand' => $i->client?->brand(),
                'accommodation_id' => $i->accommodation_id,
                'accommodation_name' => $i->accommodation?->name,
                'pitch' => $i->client?->invitationPitch($i->accommodation?->name),
                'expires_at' => $i->expires_at,
            ])
            ->values()
            ->all();
    }

    public function respondAsOwner(Invitation $invitation, User $owner, bool $accept): void
    {
        abort_unless($invitation->isUsable(), 410, 'La invitación ya no es válida.');
        abort_if($this->resolveCase($invitation) !== 'consent', 422, 'Esta invitación se responde desde el enlace del mail.');
        abort_unless(
            $owner->isAccount() && $owner->account_id !== null && $invitation->accommodation->account_id === $owner->account_id,
            403,
            'Solo el dueño del alojamiento puede responder.'
        );

        if (! $accept) {
            $this->markDeclinedWithPivot($invitation);

            return;
        }

        DB::transaction(function () use ($invitation, $owner) {
            $invitation = Invitation::whereKey($invitation->id)->lockForUpdate()->first();
            abort_unless($invitation->isUsable(), 410, 'La invitación ya no es válida.');

            $this->linkPivot($invitation, $invitation->accommodation_id, 'active');
            $invitation->markAccepted($owner);
        });
    }

    private function markDeclinedWithPivot(Invitation $invitation): void
    {
        DB::transaction(function () use ($invitation) {
            $invitation->markDeclined();

            if ($invitation->client_id && $invitation->accommodation_id) {
                $client = Client::find($invitation->client_id);
                if ($client && $client->accommodations()->where('accommodations.id', $invitation->accommodation_id)->exists()) {
                    $client->accommodations()->updateExistingPivot($invitation->accommodation_id, [
                        'status' => 'rejected',
                        'agreed_at' => null,
                    ]);
                }
            }
        });
    }

    /**
     * A quién se le escribe. Con dueño, al dueño: es quien tiene que aceptar y el
     * accept exige estar logueado con ese email. Sin dueño, al email del registro
     * o al que cargó el client.
     */
    private function recipientFor(Accommodation $accommodation, ?string $override): ?string
    {
        if ($accommodation->account_id !== null) {
            $owner = User::where('account_id', $accommodation->account_id)
                ->where('user_type', User::TYPE_ACCOUNT)
                ->oldest('id')
                ->value('email');

            return $owner !== null ? strtolower($owner) : null;
        }

        $email = $override ?: strtolower(trim((string) $accommodation->email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public function associates(int $clientId, array $filters = []): array
    {
        $client = Client::findOrFail($clientId);
        $state = self::STATE_SQL;

        $base = DB::table('accommodation_client as ac')
            ->join('accommodations as a', 'a.id', '=', 'ac.accommodation_id')
            ->where('ac.client_id', $clientId)
            ->whereNull('a.deleted_at');

        $counts = (clone $base)
            ->selectRaw("{$state} as state, count(*) as total")
            ->groupBy(DB::raw($state))
            ->pluck('total', 'state');

        $publishedCount = (clone $base)->where('ac.status', 'active')->whereNotNull('ac.verified_at')->count();

        // Filtro por cuota: el estado se calcula en PHP (imputación FIFO), así que
        // se resuelve a una lista de ids antes de paginar. Un padrón son cientos.
        $duesFilter = $client->chargesMembershipDues() && in_array($filters['dues'] ?? null, [
            DuesRepository::STATUS_NO_FEE, DuesRepository::STATUS_CURRENT, DuesRepository::STATUS_OWES, DuesRepository::STATUS_DELINQUENT, 'con_deuda',
        ], true) ? $filters['dues'] : null;
        $duesIds = null;
        if ($duesFilter !== null) {
            $memberIds = (clone $base)->where('ac.status', 'active')->whereNotNull('ac.agreed_at')->pluck('ac.accommodation_id')->map(fn ($id) => (int) $id)->all();
            $duesIds = collect($this->dues->standing($client, AssociateType::ACCOMMODATION, $memberIds))
                ->filter(fn (array $st) => $duesFilter === 'con_deuda' ? $st['overdue_count'] > 0 : $st['status'] === $duesFilter)
                ->keys()->all();
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 100);

        $page = (clone $base)
            ->leftJoin('cities as c', 'c.id', '=', 'a.city_id')
            ->leftJoin('invitations as i', function ($join) {
                $join->on('i.client_id', '=', 'ac.client_id')
                    ->on('i.accommodation_id', '=', 'ac.accommodation_id')
                    ->whereNull('i.accepted_at')
                    ->whereNull('i.declined_at')
                    ->where('i.expires_at', '>', now());
            })
            ->when(in_array($filters['state'] ?? null, self::STATES, true), fn ($q) => $q->whereRaw("{$state} = ?", [$filters['state']]))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereRaw('unaccent(a.name) ilike unaccent(?)', ['%'.$search.'%']))
            ->when($filters['city_id'] ?? null, fn ($q, $cityId) => $q->where('a.city_id', $cityId))
            ->when($filters['published'] ?? false, fn ($q) => $q->where('ac.status', 'active')->whereNotNull('ac.verified_at'))
            ->when($duesIds !== null, fn ($q) => $q->whereIn('ac.accommodation_id', $duesIds))
            ->orderBy('a.name')
            ->select([
                'a.id as accommodation_id', 'a.name', 'a.slug', 'a.account_id', 'a.email',
                'c.name as city', 'ac.agreed_at', 'ac.verified_at', 'ac.status as pivot_status',
                'i.id as invitation_id', 'i.expires_at as invitation_expires_at',
            ])
            ->selectRaw("{$state} as state")
            ->paginate($perPage, ['*'], 'page', max((int) ($filters['page'] ?? 1), 1));

        $standing = $client->chargesMembershipDues()
            ? $this->dues->standing($client, AssociateType::ACCOMMODATION, collect($page->items())->pluck('accommodation_id')->map(fn ($id) => (int) $id)->all())
            : null;

        $associates = collect($page->items())->map(fn (object $row) => [
            'entity_type' => AssociateType::ACCOMMODATION,
            'entity_id' => $row->accommodation_id,
            'accommodation_id' => $row->accommodation_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'city' => $row->city,
            'state' => $row->state,
            'published' => $row->verified_at !== null,
            // Por qué no se puede habilitar (null = se puede). Mismas reglas que
            // `PublicationRepository`; la UI no las re-deriva.
            'publish_blocker' => match (true) {
                $row->pivot_status !== 'active' => PublicationRepository::SKIP_REJECTED,
                $row->account_id === null => PublicationRepository::SKIP_NO_OWNER,
                $client->publishRequiresAgreement() && $row->agreed_at === null => PublicationRepository::SKIP_NOT_AGREED,
                default => null,
            },
            'dues' => $standing === null || $row->agreed_at === null ? null : $standing[(int) $row->accommodation_id],
            'agreed_at' => $row->agreed_at,
            // Si se puede invitar sin pedirle un email al client. El email en sí
            // no se expone: con dueño, es el de su cuenta.
            'has_email' => $row->account_id !== null || filter_var((string) $row->email, FILTER_VALIDATE_EMAIL) !== false,
            'invitation' => $row->invitation_id === null ? null : [
                'id' => $row->invitation_id,
                'expires_at' => $row->invitation_expires_at,
            ],
        ])->values();

        // Invitaciones por email (alta de un alojamiento que todavía no existe).
        $pendingInvitations = Invitation::where('client_id', $clientId)
            ->whereNull('accommodation_id')
            ->pending()
            ->latest('id')
            ->get()
            ->map(fn (Invitation $i) => [
                'invitation_id' => $i->id,
                'email' => $i->email,
                'contact_name' => $i->contact_name,
                'accommodation_id' => null,
                'accommodation_name' => $i->accommodation_name,
                'state' => 'invitado',
                'expires_at' => $i->expires_at,
            ])->values();

        return [
            'client_type' => $client->type,
            'vocabulary' => $client->relationshipVocabulary(),
            // Una agencia que se registró sola no invita hasta que el staff la verifica.
            'can_invite' => ! $client->needsVerification(),
            'publish_requires_agreement' => $client->publishRequiresAgreement(),
            'charges_dues' => $client->chargesMembershipDues(),
            'published_count' => $publishedCount,
            'counts' => collect(self::STATES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])
                ->put('total', (int) $counts->sum()),
            'associates' => [
                'data' => $associates,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
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

        // Aceptar la invitación ES aceptar el vínculo (convenio, socio, adhesión).
        $attributes = ['status' => $status, 'agreed_at' => now(), 'invitation_id' => $invitation->id];

        if ($client->accommodations()->where('accommodations.id', $accommodationId)->exists()) {
            $client->accommodations()->updateExistingPivot($accommodationId, $attributes);
        } else {
            $client->accommodations()->attach($accommodationId, $attributes);
        }
    }
}
