<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Subscription;
use App\Repositories\AccommodationBillingRepository;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alojamientos con plan pago y sin suscripción viva: quedaron así cuando el
 * CRM editaba `plan_id` a mano. Les crea la suscripción por `activate()`
 * (mensual, método `manual`, precio de lista de hoy), así el listado de
 * suscripciones del CRM muestra lo que de verdad tienen. Idempotente: un
 * segundo pase no encuentra a nadie.
 */
class BackfillManualSubscriptions extends Command
{
    protected $signature = 'billing:backfill-subscriptions {--dry-run : Sólo listar, sin crear nada}';

    protected $description = 'Crea la suscripción (manual) que les falta a los alojamientos con plan pago';

    public function handle(AccommodationBillingRepository $billing): int
    {
        $pending = Accommodation::query()
            ->whereHas('plan', fn (Builder $q) => $q->where('price', '>', 0))
            ->whereDoesntHave('subscriptions', fn (Builder $q) => $q->whereIn('status', Subscription::LIVE_STATUSES))
            ->with('plan')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No hay alojamientos con plan pago sin suscripción.');

            return self::SUCCESS;
        }

        $this->table(['id', 'alojamiento', 'plan'], $pending->map(fn (Accommodation $a) => [$a->id, $a->name, $a->plan->name]));

        if ($this->option('dry-run')) {
            $this->comment("{$pending->count()} sin tocar (--dry-run).");

            return self::SUCCESS;
        }

        foreach ($pending as $accommodation) {
            $billing->activate($accommodation, $accommodation->plan, 'monthly', Subscription::METHOD_MANUAL);
        }
        $this->info("{$pending->count()} suscripciones creadas.");

        return self::SUCCESS;
    }
}
