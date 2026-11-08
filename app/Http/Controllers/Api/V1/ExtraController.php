<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Extras\UpsertExtraRequest;
use App\Repositories\ExtraRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/** Extras del PMS (Tarifas → Extras). Ver docs/extras.md. */
class ExtraController extends BaseController
{
    public function __construct(private ExtraRepository $extras) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->extras->index($request->integer('accommodation_id')));
    }

    public function store(UpsertExtraRequest $request): JsonResponse
    {
        return response()->json($this->extras->store($request->validated()), 201);
    }

    public function update(UpsertExtraRequest $request, int $id): JsonResponse
    {
        return response()->json($this->extras->update($id, $request->validated()));
    }

    public function destroy(int $id): JsonResponse
    {
        return response()->json($this->extras->destroy($id));
    }

    public function removeFromReservation(string $id, int $line): JsonResponse
    {
        $this->extras->removeFromReservation($id, $line);

        return response()->json(['ok' => true]);
    }
}
