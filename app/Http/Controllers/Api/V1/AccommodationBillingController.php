<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\AccommodationBillingRepository;
use App\Repositories\LegalRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/** Facturación del alojamiento y aceptaciones legales en el PMS. */
class AccommodationBillingController extends BaseController
{
    public function __construct(
        private AccommodationBillingRepository $billing,
        private LegalRepository $legal,
    ) {}

    public function summary(Request $request)
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->billing->summary($request->integer('accommodation_id')));
    }

    public function requestChange(Request $request)
    {
        $data = $request->validate([
            'accommodation_id' => ['required', 'integer'],
            'plan' => ['required', 'string', 'max:100'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'note' => ['nullable', 'string', 'max:255'],
            'confirm_over_limit' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->billing->requestChange($data, $request->user()), 201);
    }

    /** Plan pago con Mercado Pago: devuelve el `checkout_url` (init_point) adonde redirigir. */
    public function startCheckout(Request $request)
    {
        $data = $request->validate([
            'accommodation_id' => ['required', 'integer'],
            'plan' => ['required', 'string', 'max:100'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'confirm_over_limit' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->billing->startCheckout($data, $request->user()), 201);
    }

    /** Vuelta desde Mercado Pago: consulta el estado real del pago pendiente. */
    public function syncCheckout(Request $request)
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->billing->syncCheckout($request->integer('accommodation_id')));
    }

    public function cancelRequest(int $id)
    {
        return response()->json($this->billing->cancelRequest($id));
    }

    public function legalStatus(Request $request)
    {
        return response()->json($this->legal->status($request->user()));
    }

    public function acceptLegal(Request $request)
    {
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['string', Rule::in(array_keys(config('legal.documents')))],
            // El PMS acepta desde su servidor: manda la IP y el navegador del usuario.
            'client_ip' => ['nullable', 'ip'],
            'client_user_agent' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->legal->accept(
            $request->user(),
            $data['documents'],
            $data['client_ip'] ?? $request->ip(),
            $data['client_user_agent'] ?? $request->userAgent(),
        ));
    }
}
