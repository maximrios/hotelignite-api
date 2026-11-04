<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Accommodation;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PlanChangeRequest;
use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Suscripciones vistas por el equipo (CRM, sólo plataforma).
 *
 * El listado es de **suscriptores** (alojamientos o clients) con su plan y su
 * suscripción viva, no de filas de `subscriptions`: un client con plan de
 * orden de compra, o un alojamiento en un plan gratis, no tiene suscripción y
 * igual tiene que verse ("sin suscripción").
 */
class SubscriptionAdminRepository
{
    public const SUBSCRIBERS = ['accommodation', 'client'];

    /** `live` = cualquier estado vivo; `none` = sin suscripción viva; `all` = sin filtrar. */
    public const STATUS_FILTERS = ['live', 'all', 'none', ...Subscription::LIVE_STATUSES];

    private const MAX_LIMIT = 100;

    /**
     * @param  array{subscriber: string, status?: string|null, plan_id?: int|null, payment_method?: string|null, search?: string|null, limit?: int|null}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $isClient = $filters['subscriber'] === 'client';
        $status = $filters['status'] ?? null;
        $method = $filters['payment_method'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $query = ($isClient ? Client::query() : Accommodation::query())
            ->with(['plan:id,slug,name,price,currency', 'liveSubscription.plan:id,slug,name'])
            ->when(! $isClient, fn (Builder $q) => $q->with('account:id,name'));

        // Un filtro de suscripción: el estado y el método se miran sobre la viva.
        $liveWhere = function (Builder $q) use ($status, $method) {
            $q->whereIn('status', in_array($status, Subscription::LIVE_STATUSES, true) ? [$status] : Subscription::LIVE_STATUSES);
            if ($method) {
                $q->where('payment_method', $method);
            }
        };

        if ($status === 'none') {
            $query->whereDoesntHave('subscriptions', fn (Builder $q) => $q->whereIn('status', Subscription::LIVE_STATUSES));
        } elseif ($status !== null && $status !== 'all') {
            $query->whereHas('subscriptions', $liveWhere);
        } elseif ($method) {
            $query->whereHas('subscriptions', $liveWhere);
        }

        $query
            ->when($filters['plan_id'] ?? null, fn (Builder $q, int $planId) => $q->where('plan_id', $planId))
            ->when($search !== '', fn (Builder $q) => $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name');

        $limit = min(max((int) ($filters['limit'] ?? 25), 1), self::MAX_LIMIT);

        return $query->paginate($limit)->through(fn (Model $m) => $this->row($m));
    }

    /**
     * Números de arriba del listado. El ingreso mensual es sobre lo que se
     * cobra de verdad: deja afuera lo asignado a mano (`manual`) y lo que
     * todavía no se autorizó (`pending`); el anual entra dividido por 12.
     */
    public function summary(): array
    {
        $byStatus = Subscription::live()
            ->select('subscriber_type', 'status', DB::raw('count(*) as total'))
            ->groupBy('subscriber_type', 'status')
            ->get();

        $count = fn (string $type) => collect(Subscription::LIVE_STATUSES)
            ->mapWithKeys(fn (string $s) => [$s => (int) ($byStatus->first(fn ($r) => $r->subscriber_type === $type && $r->status === $s)?->total ?? 0)])
            ->all();

        $mrr = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereIn('payment_method', Subscription::BILLED_METHODS)
            ->where('currency', 'ARS')
            ->get(['price', 'billing_period'])
            ->sum(fn (Subscription $s) => $s->billing_period === 'yearly' ? intdiv($s->price, 12) : $s->price);

        // Plan pago sin suscripción viva: o un dato viejo, o un client que paga
        // por orden de compra fuera del sistema. El CRM lo muestra como alerta.
        $unbilled = fn (string $model) => $model::query()
            ->whereHas('plan', fn (Builder $q) => $q->where('price', '>', 0))
            ->whereDoesntHave('subscriptions', fn (Builder $q) => $q->whereIn('status', Subscription::LIVE_STATUSES))
            ->count();

        return [
            'accommodations' => [
                'by_status' => $count(Accommodation::class),
                'paid_plan_without_subscription' => $unbilled(Accommodation::class),
            ],
            'clients' => [
                'total' => Client::count(),
                'by_status' => $count(Client::class),
                'paid_plan_without_subscription' => $unbilled(Client::class),
            ],
            'mrr' => ['amount' => $mrr, 'currency' => 'ARS'],
            'pending_plan_requests' => PlanChangeRequest::where('status', PlanChangeRequest::PENDING)->count(),
        ];
    }

    /** Ficha de facturación de un suscriptor: plan, suscripción viva, historial y últimos cobros. */
    public function detail(Accommodation|Client $subscriber): array
    {
        $subscriber->load(['plan:id,slug,name,price,currency', 'liveSubscription.plan:id,slug,name']);

        $history = $subscriber->subscriptions()->with('plan:id,slug,name')->latest('id')->limit(10)->get()
            ->map(fn (Subscription $s) => $this->subscription($s));

        $payments = Payment::where('payer_type', $subscriber->getMorphClass())
            ->where('payer_id', $subscriber->getKey())
            ->latest('id')->limit(10)
            ->get(['id', 'amount', 'currency', 'status', 'provider', 'concept', 'paid_at', 'created_at'])
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'provider' => $p->provider,
                'concept' => $p->concept,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        $pending = $subscriber instanceof Accommodation
            ? $subscriber->planChangeRequests()->where('status', PlanChangeRequest::PENDING)->with('toPlan:id,slug,name')->first()
            : null;

        return [
            ...$this->row($subscriber),
            'history' => $history,
            'payments' => $payments,
            'pending_request' => $pending ? [
                'id' => $pending->id,
                'plan' => $pending->toPlan ? ['id' => $pending->toPlan->id, 'slug' => $pending->toPlan->slug, 'name' => $pending->toPlan->name] : null,
                'billing_period' => $pending->billing_period,
                'payment_method' => $pending->payment_method,
                'created_at' => $pending->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function row(Model $m): array
    {
        $isClient = $m instanceof Client;
        $plan = $m->plan;
        $live = $m->liveSubscription;

        return [
            'subscriber' => [
                'type' => $isClient ? 'client' : 'accommodation',
                'id' => $m->id,
                'name' => $m->name,
                'client_type' => $isClient ? $m->type : null,
                'account' => ! $isClient && $m->account ? ['id' => $m->account->id, 'name' => $m->account->name] : null,
            ],
            'plan' => $plan ? [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price' => (int) $plan->price,
                'currency' => $plan->currency,
                'is_free' => $plan->isFree(),
            ] : null,
            'subscription' => $live ? $this->subscription($live) : null,
        ];
    }

    private function subscription(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status,
            'plan' => $s->plan ? ['id' => $s->plan->id, 'slug' => $s->plan->slug, 'name' => $s->plan->name] : null,
            'billing_period' => $s->billing_period,
            'price' => $s->price,
            'currency' => $s->currency,
            'payment_method' => $s->payment_method,
            'current_period_start' => $s->current_period_start?->toDateString(),
            'current_period_end' => $s->current_period_end?->toDateString(),
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'purchase_order_ref' => $s->purchase_order_ref,
            'provider_subscription_id' => $s->provider_subscription_id,
            'canceled_at' => $s->canceled_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
