<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\ClientPanel\LedgerEntryRequest;
use App\Http\Requests\ClientPanel\MembershipFeeRequest;
use App\Models\Client;
use App\Models\MemberLedgerEntry;
use App\Models\MembershipFee;
use App\Repositories\Contracts\DuesInterface;
use App\Support\AssociateType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/**
 * Cuotas de socios del client: categorías, emisión mensual, cuenta corriente y
 * tablero de cobranza. Sólo para los tipos de `Client::MEMBERSHIP_DUES_TYPES`.
 * Tenencia desde el token; la lógica vive en `DuesRepository`.
 */
class DuesController extends BaseController
{
    public function __construct(private DuesInterface $dues) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dues->summary($this->client($request), $this->period($request)));
    }

    public function members(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dues->members($this->client($request), AssociateType::ACCOMMODATION)]);
    }

    public function account(Request $request, string $type, int $id): JsonResponse
    {
        return response()->json($this->dues->account($this->client($request), $type, $id));
    }

    public function record(LedgerEntryRequest $request, string $type, int $id): JsonResponse
    {
        $client = $this->client($request);

        return response()->json($this->dues->record($client, $request->user(), $type, $id, $request->validated()), 201);
    }

    public function reverse(Request $request, MemberLedgerEntry $entry): JsonResponse
    {
        return response()->json($this->dues->reverse($this->client($request), $request->user(), $entry));
    }

    public function fees(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dues->fees($this->client($request))]);
    }

    public function storeFee(MembershipFeeRequest $request): JsonResponse
    {
        return response()->json($this->dues->saveFee($this->client($request), $request->validated()), 201);
    }

    public function updateFee(MembershipFeeRequest $request, MembershipFee $fee): JsonResponse
    {
        $client = $this->client($request);
        abort_if((int) $fee->client_id !== $client->id, 404);

        return response()->json($this->dues->saveFee($client, $request->validated(), $fee));
    }

    public function destroyFee(Request $request, MembershipFee $fee): JsonResponse
    {
        abort_if((int) $fee->client_id !== $this->client($request)->id, 404);
        $this->dues->deleteFee($fee);

        return response()->json(null, 204);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(AssociateType::all())],
            'entity_ids' => ['required', 'array', 'min:1', 'max:500'],
            'entity_ids.*' => ['integer', 'min:1'],
            'membership_fee_id' => ['present', 'nullable', 'integer'],
        ]);

        return response()->json($this->dues->assign(
            $this->client($request),
            $data['entity_type'],
            $data['entity_ids'],
            $data['membership_fee_id'],
        ));
    }

    public function emissionPreview(Request $request): JsonResponse
    {
        return response()->json($this->dues->emissionPreview($this->client($request), $this->period($request)));
    }

    public function emit(Request $request): JsonResponse
    {
        $client = $this->client($request);

        return response()->json($this->dues->emit($client, $request->user(), $this->period($request)), 201);
    }

    private function client(Request $request): Client
    {
        $client = $request->user()->client;
        abort_unless($client?->chargesMembershipDues(), 403, 'Tu cuenta no lleva cuotas de socios.');

        return $client;
    }

    /** `YYYY-MM`; por defecto el mes en curso. Se puede emitir hasta el mes siguiente. */
    private function period(Request $request): string
    {
        $period = $request->input('period') ?: CarbonImmutable::today()->format('Y-m');
        abort_unless(is_string($period) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1, 422, 'Período inválido.');
        abort_if($period > CarbonImmutable::today()->addMonthNoOverflow()->format('Y-m'), 422, 'Sólo se puede emitir hasta el mes que viene.');

        return $period;
    }
}
