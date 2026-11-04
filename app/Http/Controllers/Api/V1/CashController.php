<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Cash\CategoryRequest;
use App\Http\Requests\Cash\CloseShiftRequest;
use App\Http\Requests\Cash\EntryFiltersRequest;
use App\Http\Requests\Cash\OpenShiftRequest;
use App\Http\Requests\Cash\ReverseEntryRequest;
use App\Http\Requests\Cash\StoreEntryRequest;
use App\Repositories\CashRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Caja del PMS: turnos, movimientos, anulaciones, cobros de reservas y reportes. Ver CashRepository. */
class CashController extends BaseController
{
    public function __construct(private CashRepository $cash) {}

    public function overview(Request $request): JsonResponse
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->cash->overview($request->integer('accommodation_id')));
    }

    public function open(OpenShiftRequest $request): JsonResponse
    {
        return response()->json($this->cash->open($request->validated(), (int) $request->user()->id), 201);
    }

    public function shifts(Request $request): JsonResponse
    {
        $request->validate(['accommodation_id' => ['required', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->cash->shifts($request->integer('accommodation_id'), max(1, $request->integer('page', 1))));
    }

    public function shift(int $id): JsonResponse
    {
        return response()->json($this->cash->shift($id));
    }

    public function close(CloseShiftRequest $request, int $id): JsonResponse
    {
        return response()->json($this->cash->close($id, $request->validated(), (int) $request->user()->id));
    }

    public function entries(EntryFiltersRequest $request): JsonResponse
    {
        return response()->json($this->cash->entries($request->validated(), max(1, $request->integer('page', 1))));
    }

    public function export(EntryFiltersRequest $request): StreamedResponse
    {
        $rows = $this->cash->exportRows($request->validated());
        $name = 'caja-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function record(StoreEntryRequest $request): JsonResponse
    {
        return response()->json($this->cash->record($request->validated(), (int) $request->user()->id), 201);
    }

    public function reverse(ReverseEntryRequest $request, int $id): JsonResponse
    {
        return response()->json($this->cash->reverse($id, trim($request->string('reason')->toString()), (int) $request->user()->id), 201);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accommodation_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($this->cash->report((int) $data['accommodation_id'], $data['from'], $data['to']));
    }

    public function reservation(string $id): JsonResponse
    {
        return response()->json($this->cash->reservation($id));
    }

    public function receivables(Request $request): JsonResponse
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->cash->receivables($request->integer('accommodation_id')));
    }

    public function categories(Request $request): JsonResponse
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->cash->categories($request->integer('accommodation_id')));
    }

    public function storeCategory(CategoryRequest $request): JsonResponse
    {
        return response()->json($this->cash->storeCategory($request->validated()), 201);
    }

    public function updateCategory(CategoryRequest $request, int $id): JsonResponse
    {
        return response()->json($this->cash->updateCategory($id, $request->validated()));
    }
}
