<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\Subscription;
use App\Repositories\AccommodationBillingRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/**
 * Pedidos de cambio de plan de los hoteleros, para el equipo (platform).
 * Aprobar = pago confirmado a mano, hasta integrar Mercado Pago.
 */
class PlanChangeRequestController extends BaseController
{
    public function __construct(private AccommodationBillingRepository $billing) {}

    public function index()
    {
        return response()->json(['data' => $this->billing->pendingRequests()]);
    }

    public function approve(Request $request, int $id)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in([Subscription::METHOD_TRANSFER, Subscription::METHOD_MERCADOPAGO])],
        ]);

        return response()->json($this->billing->approve($id, $request->user(), $data['payment_method'] ?? null));
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->billing->reject($id, $request->user(), $data['note'] ?? null));
    }
}
