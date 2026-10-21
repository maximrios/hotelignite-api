<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AiCreditTransaction;
use App\Models\Client;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Repositories\Contracts\BillingInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillingRepository implements BillingInterface
{
    public function clientSummary(Client $client): array
    {
        $client->loadMissing('plan.features');
        $now = now();

        $subscription = $client->subscriptions()->live()->latest('id')->first();

        return [
            'client' => [
                'name' => $client->name,
                'type' => $client->type,
                'legal_name' => $client->legal_name,
                'tax_id' => $client->tax_id,
                'iva_condition' => $client->iva_condition,
            ],
            // Cómo paga: un municipio o una cámara no se suscribe con tarjeta.
            'payment_channel' => $client->paysWithPurchaseOrder()
                ? Subscription::METHOD_PURCHASE_ORDER
                : Subscription::METHOD_MERCADOPAGO,
            'plan' => $client->plan ? $this->plan($client->plan) : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'payment_method' => $subscription->payment_method,
                'billing_period' => $subscription->billing_period,
                'price' => $subscription->price,
                'currency' => $subscription->currency,
                'current_period_start' => $subscription->current_period_start?->toDateString(),
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'purchase_order_ref' => $subscription->purchase_order_ref,
            ] : null,
            'usage' => [
                'period' => $now->format('Y-m'),
                'associates' => $this->associatesUsage($client),
                'api_requests' => [
                    'used' => (int) DB::table('client_api_usage')
                        ->where('client_id', $client->id)
                        ->whereBetween('date', [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()])
                        ->sum('count'),
                    'limit' => $this->limit($client, 'api_requests_month'),
                ],
                'ai_credits' => [
                    'used' => -1 * (int) $client->aiCreditTransactions()
                        ->where('reason', AiCreditTransaction::REASON_USAGE)
                        ->where('period', $now->format('Y-m'))
                        ->sum('amount'),
                    'limit' => $this->limit($client, 'ai_credits_month'),
                ],
            ],
            'available_plans' => $this->plansFor($client)->map(fn (Plan $plan) => $this->plan($plan))->values(),
            'invoices' => $client->invoices()->latest('issued_at')->limit(24)->get()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'type' => $invoice->type,
                'number' => $invoice->number,
                'concept' => $invoice->concept,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'issued_at' => $invoice->issued_at->toDateString(),
                'status' => $invoice->status,
                'pdf_url' => $invoice->pdf_url,
            ])->values(),
        ];
    }

    public function plansFor(Client $client): Collection
    {
        return Plan::forAudience(Plan::AUDIENCE_CLIENT)
            ->where('enabled', true)
            ->where('is_public', true)
            ->with('features')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Plan $plan) => $plan->offeredTo($client->type))
            ->values();
    }

    public function clientQuote(Client $client, Plan $plan): array
    {
        $issuedAt = now();
        $validUntil = $issuedAt->copy()->addDays((int) config('billing.quote_validity_days'));
        $seller = config('billing.seller');
        $yearly = $plan->billing_period === 'yearly';

        return [
            'number' => sprintf('P-%04d-%s-%d', $client->id, $issuedAt->format('Ymd'), $plan->id),
            'issued_at' => $issuedAt->toDateString(),
            'valid_until' => $validUntil->toDateString(),
            'seller' => $seller,
            // Sin razón social y CUIT, Compras no puede adjuntarlo al expediente.
            'seller_complete' => filled($seller['legal_name'] ?? null) && filled($seller['tax_id'] ?? null),
            'buyer' => [
                'name' => $client->name,
                'legal_name' => $client->legal_name,
                'tax_id' => $client->tax_id,
                'iva_condition' => $client->iva_condition,
            ],
            'items' => [[
                'description' => sprintf(
                    'Licencia %s HotelIgnite — Plan %s (%s)',
                    $yearly ? 'anual' : 'mensual',
                    $plan->name,
                    $yearly ? '12 meses desde la orden de compra' : '1 mes',
                ),
                'quantity' => 1,
                'unit_price' => $plan->price,
                'amount' => $plan->price,
            ]],
            'features' => $this->plan($plan->loadMissing('features'))['features'],
            'total' => $plan->price,
            'currency' => $plan->currency,
            'price_provisional' => $plan->price_provisional,
        ];
    }

    /** @return array<string, mixed> */
    private function plan(Plan $plan): array
    {
        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'subtitle' => $plan->subtitle,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_period' => $plan->billing_period,
            'price_provisional' => $plan->price_provisional,
            'features' => $plan->features->sortBy('id')->map(fn (Feature $feature) => [
                'slug' => $feature->slug,
                'name' => $feature->name,
                'type' => $feature->type,
                'limit' => $feature->pivot->limit,
            ])->values(),
        ];
    }

    /**
     * Límite de una feature de tipo `limit`: entero, `null` (= ilimitado) o
     * `false` si el plan no la incluye o no hay plan.
     */
    private function limit(Client $client, string $slug): int|false|null
    {
        return $client->hasFeature($slug) ? $client->featureLimit($slug) : false;
    }

    /** @return array{published: int, in_padron: int, limit: int|false|null} */
    private function associatesUsage(Client $client): array
    {
        $active = $client->accommodations()->wherePivot('status', 'active');

        return [
            // "Publicado" = habilitado por el client (`verified_at`), que es el gate de publicación.
            'published' => (clone $active)->wherePivotNotNull('verified_at')->count(),
            'in_padron' => $active->count(),
            'limit' => $this->limit($client, 'associates_published'),
        ];
    }
}
