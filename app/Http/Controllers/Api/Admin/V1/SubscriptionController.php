<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\Accommodation;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Repositories\AccommodationBillingRepository;
use App\Repositories\SubscriptionAdminRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/**
 * Suscripciones para el equipo (CRM, middleware `platform`): listado de
 * alojamientos y clients con su plan y su suscripción viva, resumen, ficha de
 * facturación de cada uno y cambio de plan de un alojamiento.
 */
class SubscriptionController extends BaseController
{
    public function __construct(
        private SubscriptionAdminRepository $subscriptions,
        private AccommodationBillingRepository $billing,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'subscriber' => ['sometimes', Rule::in(SubscriptionAdminRepository::SUBSCRIBERS)],
            'status' => ['sometimes', 'nullable', Rule::in(SubscriptionAdminRepository::STATUS_FILTERS)],
            'plan_id' => ['sometimes', 'nullable', 'integer'],
            'payment_method' => ['sometimes', 'nullable', Rule::in([...Subscription::BILLED_METHODS, Subscription::METHOD_MANUAL])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $data['subscriber'] ??= 'accommodation';
        // Default por tipo: de alojamientos, sólo los que tienen suscripción (si
        // no son ~760 en Free); de clients, todos (son pocos y casi ninguno la tiene).
        $data['status'] ??= $data['subscriber'] === 'client' ? 'all' : 'live';

        $page = $this->subscriptions->list($data);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function summary()
    {
        return response()->json(['data' => $this->subscriptions->summary()]);
    }

    public function showAccommodation(Accommodation $accommodation)
    {
        return response()->json(['data' => $this->subscriptions->detail($accommodation)]);
    }

    public function showClient(Client $client)
    {
        return response()->json(['data' => $this->subscriptions->detail($client)]);
    }

    /**
     * Cambio de plan desde el CRM. `manual` = sin cobro registrado (cortesía,
     * prueba); `transfer` = el hotelero paga por transferencia.
     */
    public function assignAccommodationPlan(Request $request, Accommodation $accommodation)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('audience', Plan::AUDIENCE_ACCOMMODATION)],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'payment_method' => ['required', Rule::in([Subscription::METHOD_MANUAL, Subscription::METHOD_TRANSFER])],
        ]);

        $this->billing->assignPlan($accommodation, Plan::findOrFail($data['plan_id']), $data['billing_period'], $data['payment_method']);

        return response()->json(['data' => $this->subscriptions->detail($accommodation->fresh())]);
    }
}
