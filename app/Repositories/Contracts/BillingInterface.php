<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Client;
use App\Models\Plan;
use Illuminate\Support\Collection;

interface BillingInterface
{
    /**
     * Estado de facturación de un client: plan vigente, suscripción, consumo
     * del mes contra los límites del plan, planes contratables y comprobantes.
     *
     * @return array<string, mixed>
     */
    public function clientSummary(Client $client): array;

    /**
     * Planes que se le ofrecen a un client según cómo opera: los que venden
     * (agencias) ven los planes con `sales`; gobierno y cámaras, los que no.
     *
     * @return Collection<int, Plan>
     */
    public function plansFor(Client $client);

    /**
     * Presupuesto formal de un plan para un client, con los datos que pide
     * Compras para abrir el expediente.
     *
     * @return array<string, mixed>
     */
    public function clientQuote(Client $client, Plan $plan): array;
}
