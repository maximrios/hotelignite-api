<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Accommodation;
use App\Models\AiCreditTransaction;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanChangeRequest;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Services\MercadoPagoSubscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Facturación del alojamiento en el PMS: plan vigente con su uso, catálogo de
 * planes, pedidos de cambio de plan, créditos de IA y comprobantes.
 *
 * Dos caminos para cambiar de plan, los dos con un `PlanChangeRequest`:
 * - Mercado Pago (`startCheckout`): suscripción preapproval en `pending`; el
 *   hotelero la autoriza en `init_point` y `syncPreapproval` (webhook o vuelta
 *   al PMS) aprueba el pedido con `activate()`.
 * - Manual (`requestChange`): lo confirma el equipo (`approve`), p. ej. por
 *   transferencia o para bajar al Free.
 */
class AccommodationBillingRepository
{
    use ScopesToAccommodation;

    public function __construct(private MercadoPagoSubscriptions $mercadoPago) {}

    /** Descuento del pago anual (sobre 12 meses). */
    public const YEARLY_DISCOUNT = 20;

    /** Features que se muestran en la comparativa de planes, en orden. */
    private const SHOWCASE = [
        'pms' => 'Calendario, reservas y huéspedes',
        'front_desk' => 'Check-in, check-out y parte de viajeros',
        'rate_management' => 'Tarifas completas: planes y temporadas',
        'housekeeping' => 'Housekeeping',
        'booking_engine' => 'Motor de reservas en tu web',
        'ai_assistant' => 'Asistente con IA',
        'pms_reports' => 'Reportes de ocupación y revenue',
        'channel_manager' => 'Channel manager',
        'invoicing_arca' => 'Facturación ARCA',
        'api_access' => 'API y webhooks',
    ];

    public function summary(int $accommodationId): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);
        $accommodation->loadMissing('plan.features');
        $period = now()->format('Y-m');

        $this->grantMonthlyCredits($accommodation, $period);

        $credits = $accommodation->aiCreditTransactions()->where('period', $period)->get();
        $subscription = $accommodation->subscriptions()->live()->latest('id')->first();
        $pending = $accommodation->planChangeRequests()->where('status', PlanChangeRequest::PENDING)->with('toPlan')->first();

        return [
            'plan' => $accommodation->plan ? $this->planPayload($accommodation->plan) : null,
            'legacy_plan' => $accommodation->plan !== null && ! $accommodation->plan->is_public,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'billing_period' => $subscription->billing_period,
                'price' => $subscription->price / 100,
                'currency' => $subscription->currency,
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'payment_method' => $subscription->payment_method,
            ] : null,
            'usage' => [
                'rooms' => [
                    'used' => Room::where('accommodation_id', $accommodation->id)->active()->count(),
                    'limit' => $accommodation->roomLimit(),
                ],
                'users' => [
                    'used' => User::where('account_id', $accommodation->account_id)->count(),
                    'limit' => $accommodation->hasFeature('users') ? $accommodation->featureLimit('users') : 1,
                ],
                'ai_credits' => [
                    'period' => $period,
                    'granted' => (int) $credits->where('amount', '>', 0)->sum('amount'),
                    'used' => (int) -$credits->where('amount', '<', 0)->sum('amount'),
                    'balance' => (int) $credits->sum('amount'),
                ],
            ],
            'pending_request' => $pending ? $this->requestPayload($pending) : null,
            'plans' => $this->catalog()->map(fn (Plan $p) => $this->planPayload($p))->values(),
            'yearly_discount' => self::YEARLY_DISCOUNT,
            'invoices' => $accommodation->invoices()->where('status', 'issued')->latest('issued_at')->limit(24)->get()
                ->map(fn ($inv) => [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'type' => $inv->type,
                    'concept' => $inv->concept,
                    'amount' => $inv->amount / 100,
                    'currency' => $inv->currency,
                    'issued_at' => $inv->issued_at?->toDateString(),
                    'pdf_url' => $inv->pdf_url,
                ])->values(),
        ];
    }

    /**
     * Pedido de cambio de plan. Uno pendiente por alojamiento; un plan
     * "próximamente" no se puede pedir (se ofrece "Avisarme" en el PMS).
     */
    public function requestChange(array $data, User $user): array
    {
        [$accommodation, $plan] = $this->resolveChange($data);

        $request = DB::transaction(function () use ($accommodation, $plan, $data, $user) {
            $this->abortIfPending($accommodation);

            return PlanChangeRequest::create([
                'accommodation_id' => $accommodation->id,
                'from_plan_id' => $accommodation->plan_id,
                'to_plan_id' => $plan->id,
                'billing_period' => $plan->isFree() ? 'free' : $data['billing_period'],
                'status' => PlanChangeRequest::PENDING,
                'requested_by_user_id' => $user->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        return $this->requestPayload($request->load('toPlan'));
    }

    /**
     * Cambio a un plan pago con Mercado Pago: crea el pedido y la suscripción
     * `pending` (monto y frecuencia del catálogo, nunca del navegador) y
     * devuelve el `init_point` adonde el PMS redirige al hotelero.
     */
    public function startCheckout(array $data, User $user): array
    {
        [$accommodation, $plan] = $this->resolveChange($data);
        if ($plan->isFree()) {
            throw ValidationException::withMessages(['plan' => 'El plan Free no se paga: pedilo sin Mercado Pago.']);
        }

        // Volver a "Suscribirme" con un pago a medio hacer del mismo plan lo retoma.
        $open = $accommodation->planChangeRequests()->where('status', PlanChangeRequest::PENDING)->first();
        if ($open?->payment_method === Subscription::METHOD_MERCADOPAGO && $open->checkout_url
            && (int) $open->to_plan_id === (int) $plan->id && $open->billing_period === $data['billing_period']) {
            return ['checkout_url' => $open->checkout_url, 'request' => $this->requestPayload($open->load('toPlan'))];
        }

        $request = DB::transaction(function () use ($accommodation, $plan, $data, $user) {
            $this->abortIfPending($accommodation);

            return PlanChangeRequest::create([
                'accommodation_id' => $accommodation->id,
                'from_plan_id' => $accommodation->plan_id,
                'to_plan_id' => $plan->id,
                'billing_period' => $data['billing_period'],
                'status' => PlanChangeRequest::PENDING,
                'requested_by_user_id' => $user->id,
                'payment_method' => Subscription::METHOD_MERCADOPAGO,
                'external_reference' => 'hi-pcr-'.Str::uuid(),
            ]);
        });

        $offer = $this->trustedOffer($plan, $data['billing_period']);
        try {
            $preapproval = $this->mercadoPago->createPending([
                'reason' => "HotelIgnite {$plan->name} ({$offer['label']}) · {$accommodation->name}",
                'external_reference' => $request->external_reference,
                'payer_email' => config('services.mercadopago.test_payer_email') ?: $user->email,
                'auto_recurring' => [
                    'frequency' => $offer['frequency'],
                    'frequency_type' => 'months',
                    'transaction_amount' => $offer['amount'],
                    'currency_id' => $offer['currency'],
                ],
                'back_url' => config('services.mercadopago.subscription_back_url'),
            ]);
        } catch (RuntimeException $e) {
            Log::error('Mercado Pago: no se pudo crear la suscripción', ['request' => $request->id, 'error' => $e->getMessage()]);
            $request->update(['status' => PlanChangeRequest::CANCELED, 'resolved_at' => now(), 'note' => 'No se pudo iniciar el pago']);
            abort(502, 'No pudimos conectar con Mercado Pago. Probá de nuevo en unos minutos.');
        }

        $request->update([
            'provider_subscription_id' => $preapproval->id,
            'checkout_url' => $preapproval->init_point,
        ]);

        return ['checkout_url' => $preapproval->init_point, 'request' => $this->requestPayload($request->load('toPlan'))];
    }

    /**
     * Vuelta del hotelero desde Mercado Pago: consulta el pago pendiente del
     * alojamiento (no confía en los parámetros de la URL de vuelta).
     */
    public function syncCheckout(int $accommodationId): array
    {
        $accommodation = $this->writableAccommodation($accommodationId);
        $request = $accommodation->planChangeRequests()
            ->where('payment_method', Subscription::METHOD_MERCADOPAGO)
            ->whereNotNull('provider_subscription_id')
            ->latest('id')
            ->first();

        if ($request === null) {
            return ['status' => null, 'request' => null];
        }
        if ($request->status === PlanChangeRequest::PENDING) {
            try {
                $this->syncPreapproval($request->provider_subscription_id);
                $request->refresh();
            } catch (RuntimeException $e) {
                // Sin respuesta de Mercado Pago queda pendiente; el webhook lo resuelve.
                Log::warning('Mercado Pago: no se pudo consultar el preapproval', ['request' => $request->id, 'error' => $e->getMessage()]);
            }
        }

        return ['status' => $request->status, 'request' => $this->requestPayload($request->load('toPlan'))];
    }

    /**
     * Aplica el estado de un preapproval de Mercado Pago. Lo llaman el webhook
     * (`subscription_preapproval`) y la vuelta al PMS; es idempotente.
     *
     * - Pedido pendiente: `authorized` lo aprueba (activa el plan); `canceled`
     *   lo cancela; `pending` sigue esperando.
     * - Suscripción viva: `paused` → `past_due`, `authorized` la reactiva,
     *   `canceled` la cancela y el alojamiento pasa al Free.
     */
    public function syncPreapproval(string $preapprovalId): void
    {
        $preapproval = $this->mercadoPago->find($preapprovalId);

        DB::transaction(function () use ($preapprovalId, $preapproval) {
            $request = PlanChangeRequest::where('provider_subscription_id', $preapprovalId)
                ->where('status', PlanChangeRequest::PENDING)
                ->lockForUpdate()
                ->first();

            if ($request !== null) {
                match ($preapproval->status) {
                    MercadoPagoSubscriptions::AUTHORIZED => $this->approveRequest($request, null, Subscription::METHOD_MERCADOPAGO, $preapprovalId),
                    MercadoPagoSubscriptions::CANCELED => $request->update(['status' => PlanChangeRequest::CANCELED, 'resolved_at' => now()]),
                    default => null,
                };

                return;
            }

            $subscription = Subscription::where('provider_subscription_id', $preapprovalId)->live()->lockForUpdate()->first();
            if ($subscription === null) {
                return;
            }

            match ($preapproval->status) {
                MercadoPagoSubscriptions::PAUSED => $subscription->update(['status' => Subscription::STATUS_PAST_DUE]),
                MercadoPagoSubscriptions::AUTHORIZED => $subscription->update(['status' => Subscription::STATUS_ACTIVE]),
                MercadoPagoSubscriptions::CANCELED => $this->dropToFree($subscription),
                default => null,
            };
        });
    }

    /**
     * Cobro recurrente (`subscription_authorized_payment`): registra el pago
     * (único por id de Mercado Pago) y, si se aprobó, corre el período desde la
     * fecha del débito. Rechazado → `past_due` (Mercado Pago reintenta solo).
     * Idempotente: todo sale del cobro, no de cuántas veces llegó el aviso.
     */
    public function syncAuthorizedPayment(int $authorizedPaymentId): void
    {
        $charge = $this->mercadoPago->findAuthorizedPayment($authorizedPaymentId);
        $payment = (array) ($charge->payment ?? []);
        if (! $charge->preapproval_id || ! isset($payment['id'])) {
            return; // Cobro programado que todavía no se intentó.
        }

        $subscription = $this->subscriptionFor($charge->preapproval_id);
        if ($subscription === null) {
            // El aviso del cobro puede llegar antes que el de la autorización.
            $this->syncPreapproval($charge->preapproval_id);
            $subscription = $this->subscriptionFor($charge->preapproval_id)
                ?? throw new RuntimeException("Sin suscripción para el preapproval {$charge->preapproval_id}");
        }

        DB::transaction(function () use ($subscription, $charge, $payment) {
            $status = match ($payment['status'] ?? null) {
                'approved' => 'approved',
                'rejected', 'cancelled' => 'rejected',
                'refunded', 'charged_back' => 'refunded',
                default => 'pending',
            };
            $debitedAt = $charge->debit_date ? Carbon::parse($charge->debit_date) : now();

            Payment::updateOrCreate(
                ['provider' => Subscription::METHOD_MERCADOPAGO, 'provider_payment_id' => (string) $payment['id']],
                [
                    'subscription_id' => $subscription->id,
                    'payer_type' => $subscription->subscriber_type,
                    'payer_id' => $subscription->subscriber_id,
                    'amount' => (int) round(((float) $charge->transaction_amount) * 100),
                    'currency' => $charge->currency_id ?? $subscription->currency,
                    'status' => $status,
                    'concept' => $charge->reason,
                    'paid_at' => $status === 'approved' ? $debitedAt : null,
                    'raw' => ['authorized_payment_id' => $charge->id, 'status' => $charge->status, 'payment' => $payment],
                ],
            );

            if ($status === 'approved') {
                $start = $debitedAt->copy()->startOfDay();
                $subscription->update([
                    'status' => Subscription::STATUS_ACTIVE,
                    'current_period_start' => $start->toDateString(),
                    'current_period_end' => ($subscription->billing_period === 'yearly' ? $start->addYear() : $start->addMonth())->toDateString(),
                ]);
            } elseif ($status === 'rejected' && $subscription->status === Subscription::STATUS_ACTIVE) {
                $subscription->update(['status' => Subscription::STATUS_PAST_DUE]);
            }
        });
    }

    private function subscriptionFor(string $preapprovalId): ?Subscription
    {
        return Subscription::where('provider_subscription_id', $preapprovalId)->live()->latest('id')->first();
    }

    /** Resuelve y valida el plan pedido (común a los dos caminos). */
    private function resolveChange(array $data): array
    {
        $accommodation = $this->writableAccommodation((int) $data['accommodation_id']);
        $plan = $this->catalog()->firstWhere('slug', $data['plan']);

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'Ese plan no está disponible.']);
        }
        if ($plan->coming_soon) {
            throw ValidationException::withMessages(['plan' => 'Ese plan todavía no está disponible: te avisamos cuando salga.']);
        }
        if ((int) $plan->id === (int) $accommodation->plan_id) {
            throw ValidationException::withMessages(['plan' => 'Ya estás en ese plan.']);
        }

        // Bajar a un plan con menos habitaciones que las cargadas no borra nada,
        // pero hay que avisarlo antes de pedirlo (las altas quedan bloqueadas).
        $limit = $plan->features->firstWhere('slug', 'rooms')?->pivot->limit;
        $rooms = Room::where('accommodation_id', $accommodation->id)->active()->count();
        if ($limit !== null && $rooms > $limit && ! ($data['confirm_over_limit'] ?? false)) {
            throw ValidationException::withMessages([
                'plan' => "Tenés {$rooms} habitaciones y ese plan permite {$limit}: no se borra nada, pero no vas a poder agregar más. Confirmá para pedirlo igual.",
            ]);
        }

        return [$accommodation, $plan];
    }

    private function abortIfPending(Accommodation $accommodation): void
    {
        abort_if(
            $accommodation->planChangeRequests()->where('status', PlanChangeRequest::PENDING)->lockForUpdate()->exists(),
            409,
            'Ya tenés un cambio de plan pendiente. Cancelalo para pedir otro.'
        );
    }

    /**
     * Monto y frecuencia de la suscripción, del catálogo del servidor. Anual =
     * un cobro cada 12 meses con el descuento de `YEARLY_DISCOUNT`.
     *
     * @return array{amount: float, currency: string, frequency: int, label: string}
     */
    private function trustedOffer(Plan $plan, string $billingPeriod): array
    {
        $yearly = $billingPeriod === 'yearly';
        $cents = $yearly ? $this->yearlyPrice((int) $plan->price) : (int) $plan->price;

        return [
            'amount' => round($cents / 100, 2),
            'currency' => $plan->currency,
            'frequency' => $yearly ? 12 : 1,
            'label' => $yearly ? 'anual' : 'mensual',
        ];
    }

    public function cancelRequest(int $id): array
    {
        $request = PlanChangeRequest::find($id);
        abort_if($request === null, 404);
        $this->writableAccommodation((int) $request->accommodation_id);
        abort_unless($request->status === PlanChangeRequest::PENDING, 422, 'Ese pedido ya se resolvió.');

        // El link de pago sigue vivo en Mercado Pago: se cancela para que no se
        // pueda autorizar después. Si falla, el webhook igual no aprueba un
        // pedido cancelado.
        if ($request->provider_subscription_id) {
            try {
                $this->mercadoPago->transition($request->provider_subscription_id, 'cancel');
            } catch (RuntimeException $e) {
                Log::warning('Mercado Pago: no se pudo cancelar el preapproval pendiente', ['request' => $request->id, 'error' => $e->getMessage()]);
            }
        }

        $request->update(['status' => PlanChangeRequest::CANCELED, 'resolved_at' => now()]);

        return $this->requestPayload($request->load('toPlan'));
    }

    // ─── Equipo de HotelIgnite (platform) ───────────────────────────────────────

    public function pendingRequests(): Collection
    {
        return PlanChangeRequest::where('status', PlanChangeRequest::PENDING)
            ->with(['accommodation:id,name,account_id', 'fromPlan:id,slug,name', 'toPlan:id,slug,name,price'])
            ->oldest()
            ->get()
            ->map(fn (PlanChangeRequest $r) => [
                ...$this->requestPayload($r),
                'accommodation' => ['id' => $r->accommodation?->id, 'name' => $r->accommodation?->name],
                'from_plan' => $r->fromPlan?->name,
            ]);
    }

    /**
     * Aprueba (pago confirmado a mano): cambia el plan y deja la suscripción
     * activa con el precio congelado. Con Mercado Pago, esto lo dispara el
     * webhook del pago aprobado.
     */
    public function approve(int $id, User $by, ?string $paymentMethod = null): array
    {
        return DB::transaction(function () use ($id, $by, $paymentMethod) {
            $request = PlanChangeRequest::lockForUpdate()->find($id);
            abort_if($request === null, 404);
            abort_unless($request->status === PlanChangeRequest::PENDING, 422, 'Ese pedido ya se resolvió.');

            $this->approveRequest($request, $by, $paymentMethod, null);

            return $this->requestPayload($request->load('toPlan'));
        });
    }

    /** Aprueba un pedido pendiente ya bloqueado. `$by` null = Mercado Pago. */
    private function approveRequest(PlanChangeRequest $request, ?User $by, ?string $paymentMethod, ?string $providerSubscriptionId): void
    {
        $accommodation = Accommodation::findOrFail($request->accommodation_id);
        $plan = Plan::findOrFail($request->to_plan_id);
        $this->activate($accommodation, $plan, $request->billing_period, $paymentMethod, $providerSubscriptionId);

        $request->update([
            'status' => PlanChangeRequest::APPROVED,
            'resolved_by_user_id' => $by?->id,
            'resolved_at' => now(),
        ]);
    }

    /** Mercado Pago canceló el débito: se cierra la suscripción y el alojamiento vuelve al Free. */
    private function dropToFree(Subscription $subscription): void
    {
        $accommodation = $subscription->subscriber;
        $free = Plan::forAudience(Plan::AUDIENCE_ACCOMMODATION)->where('slug', 'free')->first();

        if ($accommodation instanceof Accommodation && $free !== null) {
            $this->activate($accommodation, $free, 'free', null);

            return;
        }
        $subscription->update(['status' => Subscription::STATUS_CANCELED, 'canceled_at' => now()]);
    }

    public function reject(int $id, User $by, ?string $note): array
    {
        $request = PlanChangeRequest::find($id);
        abort_if($request === null, 404);
        abort_unless($request->status === PlanChangeRequest::PENDING, 422, 'Ese pedido ya se resolvió.');

        $request->update([
            'status' => PlanChangeRequest::REJECTED,
            'resolved_by_user_id' => $by->id,
            'resolved_at' => now(),
            'note' => $note ?? $request->note,
        ]);

        return $this->requestPayload($request->load('toPlan'));
    }

    /**
     * Pone el plan y la suscripción. Bajar al Free cancela la suscripción viva.
     * Si la anterior se cobraba con Mercado Pago, se cancela también allá (si
     * no, seguiría debitando junto con la nueva); un error corta todo.
     */
    public function activate(Accommodation $accommodation, Plan $plan, string $billingPeriod, ?string $paymentMethod, ?string $providerSubscriptionId = null): void
    {
        $previous = $accommodation->subscriptions()->live()
            ->whereNotNull('provider_subscription_id')
            ->where('provider_subscription_id', '!=', (string) $providerSubscriptionId)
            ->pluck('provider_subscription_id');
        foreach ($previous as $preapprovalId) {
            if ($this->mercadoPago->find($preapprovalId)->status !== MercadoPagoSubscriptions::CANCELED) {
                $this->mercadoPago->transition($preapprovalId, 'cancel');
            }
        }

        $accommodation->subscriptions()->live()->update([
            'status' => Subscription::STATUS_CANCELED,
            'canceled_at' => now(),
        ]);

        if (! $plan->isFree()) {
            $yearly = $billingPeriod === 'yearly';
            $accommodation->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_period' => $yearly ? 'yearly' : 'monthly',
                'price' => $yearly ? $this->yearlyPrice((int) $plan->price) : (int) $plan->price,
                'currency' => $plan->currency,
                'payment_method' => $paymentMethod ?? Subscription::METHOD_TRANSFER,
                'provider_subscription_id' => $providerSubscriptionId,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => ($yearly ? now()->addYear() : now()->addMonth())->toDateString(),
            ]);
        }

        $accommodation->plan_id = $plan->id;
        $accommodation->save();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /** Planes ofrecidos al hotelero (públicos y habilitados). */
    private function catalog(): Collection
    {
        return Plan::forAudience(Plan::AUDIENCE_ACCOMMODATION)
            ->where('is_public', true)
            ->where('enabled', true)
            ->with('features')
            ->orderBy('sort_order')
            ->get();
    }

    private function planPayload(Plan $plan): array
    {
        $limit = fn (string $slug) => $plan->features->firstWhere('slug', $slug)?->pivot->limit;
        $has = fn (string $slug) => $plan->features->contains('slug', $slug);

        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'subtitle' => $plan->subtitle,
            'description' => $plan->description,
            'price_monthly' => $plan->price / 100,
            'price_yearly' => $this->yearlyPrice((int) $plan->price) / 100,
            'currency' => $plan->currency,
            'free' => $plan->isFree(),
            'coming_soon' => (bool) $plan->coming_soon,
            'limits' => [
                'rooms' => $has('rooms') ? $limit('rooms') : Accommodation::FREE_ROOM_LIMIT,
                'users' => $has('users') ? $limit('users') : 1,
                'ai_credits' => $has('ai_assistant') ? $limit('ai_assistant') : 0,
                'channels' => $has('channel_manager') ? $limit('channels_connected') : 0,
            ],
            'features' => collect(self::SHOWCASE)
                ->map(fn (string $label, string $slug) => ['slug' => $slug, 'label' => $label, 'included' => $has($slug)])
                ->values(),
            'feature_slugs' => $plan->features->pluck('slug')->values(),
        ];
    }

    private function requestPayload(PlanChangeRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'plan' => $r->toPlan ? ['slug' => $r->toPlan->slug, 'name' => $r->toPlan->name] : null,
            'billing_period' => $r->billing_period,
            'payment_method' => $r->payment_method,
            // Sólo mientras está pendiente: para retomar el pago en Mercado Pago.
            'checkout_url' => $r->status === PlanChangeRequest::PENDING ? $r->checkout_url : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
            'note' => $r->note,
        ];
    }

    private function yearlyPrice(int $monthly): int
    {
        return (int) round($monthly * 12 * (100 - self::YEARLY_DISCOUNT) / 100);
    }

    /**
     * Créditos de IA del mes según el plan (límite de `ai_assistant`). Una vez
     * por período (índice único parcial); si ya se otorgó, no hace nada.
     */
    private function grantMonthlyCredits(Accommodation $accommodation, string $period): void
    {
        $credits = $accommodation->hasFeature('ai_assistant') ? (int) $accommodation->featureLimit('ai_assistant') : 0;
        if ($credits <= 0) {
            return;
        }

        $already = $accommodation->aiCreditTransactions()
            ->where('period', $period)
            ->where('reason', AiCreditTransaction::REASON_PLAN_GRANT)
            ->exists();
        if ($already) {
            return;
        }

        $accommodation->aiCreditTransactions()->create([
            'amount' => $credits,
            'reason' => AiCreditTransaction::REASON_PLAN_GRANT,
            'period' => $period,
            'description' => "Créditos incluidos en el plan {$accommodation->plan->name}",
        ]);
    }
}
