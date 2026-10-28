<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Housekeeping\ActionRequest;
use App\Http\Requests\Housekeeping\BulkAssignRequest;
use App\Http\Requests\Housekeeping\DndRequest;
use App\Http\Requests\Housekeeping\ProblemRequest;
use App\Http\Requests\Housekeeping\SettingsRequest;
use App\Http\Requests\Housekeeping\StaffRequest;
use App\Http\Requests\Housekeeping\StoreTaskRequest;
use App\Repositories\HousekeepingRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/** Housekeeping del PMS (tablero del día, tareas, personal, historial). Ver HousekeepingRepository. */
class HousekeepingController extends BaseController
{
    public function __construct(private HousekeepingRepository $housekeeping) {}

    public function board(Request $request)
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->housekeeping->board($request->integer('accommodation_id')));
    }

    public function storeTask(StoreTaskRequest $request)
    {
        return response()->json($this->housekeeping->storeManualTask($request->validated()), 201);
    }

    public function assignTask(ActionRequest $request, int $id)
    {
        return response()->json($this->housekeeping->assignTask($id, $request->validated()));
    }

    public function bulkAssign(BulkAssignRequest $request)
    {
        return response()->json($this->housekeeping->bulkAssign($request->validated()));
    }

    public function startTask(ActionRequest $request, int $id)
    {
        return response()->json($this->housekeeping->startTask($id, $request->validated()));
    }

    public function finishTask(ActionRequest $request, int $id)
    {
        return response()->json($this->housekeeping->finishTask($id, $request->validated()));
    }

    public function cancelTask(int $id)
    {
        return response()->json($this->housekeeping->cancelOpenTask($id));
    }

    public function clean(ActionRequest $request, int $id)
    {
        return response()->json($this->housekeeping->clean($id, $request->validated()));
    }

    public function inspect(ActionRequest $request, int $id)
    {
        return response()->json($this->housekeeping->inspect($id, $request->validated()));
    }

    public function dirty(ActionRequest $request, int $id)
    {
        return response()->json($this->housekeeping->dirty($id, $request->validated()));
    }

    public function problem(ProblemRequest $request, int $id)
    {
        return response()->json($this->housekeeping->problem($id, $request->validated()));
    }

    public function resolve(int $id)
    {
        return response()->json($this->housekeeping->resolve($id));
    }

    public function dnd(DndRequest $request, int $id)
    {
        return response()->json($this->housekeeping->dnd($id, $request->validated()));
    }

    public function events(Request $request, int $id)
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'array'],
            'type.*' => ['string', 'max:30'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($this->housekeeping->events($id, $filters));
    }

    public function storeStaff(StaffRequest $request)
    {
        return response()->json($this->housekeeping->storeStaff($request->validated()), 201);
    }

    public function updateStaff(StaffRequest $request, int $id)
    {
        return response()->json($this->housekeeping->updateStaff($id, $request->validated()));
    }

    public function destroyStaff(int $id)
    {
        return response()->json($this->housekeeping->destroyStaff($id));
    }

    public function updateSettings(SettingsRequest $request)
    {
        return response()->json($this->housekeeping->updateSettings($request->validated()));
    }
}
