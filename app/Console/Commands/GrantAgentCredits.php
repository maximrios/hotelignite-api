<?php

namespace App\Console\Commands;

use App\Agents\AgentProvisioner;
use App\Models\Accommodation;
use App\Models\AccommodationAgent;
use App\Services\Colmexa\ColmexaException;
use Illuminate\Console\Command;

/**
 * Carga en Colmexa las respuestas del mes de cada alojamiento con un agente
 * activo. Corre todos los días y no una vez por mes a propósito: la carga es
 * idempotente por mes (`plan-YYYY-MM`), así que un día caído o un hotel en otra
 * zona horaria se corrigen solos en la corrida siguiente.
 */
class GrantAgentCredits extends Command
{
    protected $signature = 'agents:grant-credits';

    protected $description = 'Carga en Colmexa las respuestas del mes de los alojamientos con agentes activos';

    public function handle(AgentProvisioner $provisioner): int
    {
        $ids = AccommodationAgent::query()
            ->where('status', AccommodationAgent::STATUS_ACTIVE)
            ->distinct()
            ->pluck('accommodation_id');

        $failed = 0;

        Accommodation::query()->with('plan.features')->whereIn('id', $ids)->each(function (Accommodation $accommodation) use ($provisioner, &$failed): void {
            try {
                $provisioner->grantMonthlyCredits($accommodation);
            } catch (ColmexaException $e) {
                $failed++;
                $this->warn("#{$accommodation->id} {$accommodation->name}: {$e->getMessage()}");
            }
        });

        $this->info("Alojamientos: {$ids->count()}, con error: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
