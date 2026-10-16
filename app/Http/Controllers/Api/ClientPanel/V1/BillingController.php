<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Models\Client;
use App\Models\Plan;
use App\Repositories\Contracts\BillingInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Facturación del client: su plan, su consumo y el presupuesto formal para
 * abrir el expediente de compra. Tenencia desde el token, nunca de la URL.
 */
class BillingController extends BaseController
{
    public function __construct(private BillingInterface $billing) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->billing->clientSummary($this->client($request)));
    }

    public function quote(Request $request): JsonResponse
    {
        $request->validate(['plan' => ['required', 'string', 'max:100']]);

        $client = $this->client($request);

        // Sólo los planes que se le ofrecen a este client: un slug de otro
        // audience o de un plan oculto responde 404, no un presupuesto.
        $plan = $this->billing->plansFor($client)->firstWhere('slug', $request->string('plan')->toString());
        abort_if($plan === null, 404, 'Plan no disponible.');

        return response()->json($this->billing->clientQuote($client, $plan));
    }

    private function client(Request $request): Client
    {
        return Client::findOrFail($request->user()->client_id);
    }
}
