<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyRatePlanRequest;
use App\Http\Requests\StoreRatePlanRequest;
use App\Http\Requests\UpdateRatePlanRequest;
use App\Http\Resources\V1\RatePlanResource;
use App\Http\Resources\V1\RatePlanResourceCollection;
use App\Models\RatePlan;
use App\Repositories\Contracts\RatePlanInterface;
use Illuminate\Http\Request;

class RatePlanRepository implements RatePlanInterface
{
    public function all(Request $request)
    {
        $limit = $request->limit ?: 10;
        $offset = $request->offset ?: 0;

        $ratePlans = RatePlan::when($request->room_type_id, function ($q, $room_type_id) {
            return $q->where('room_type_id', $room_type_id);
        })
            ->when($request->enabled !== null, function ($q) use ($request) {
                return $q->where('enabled', $request->enabled);
            })
            ->with('roomType')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return new RatePlanResourceCollection($ratePlans);
    }

    public function find($id)
    {
        $ratePlan = RatePlan::with(['roomType', 'rates'])->find($id);

        return new RatePlanResource($ratePlan);
    }

    public function search($request)
    {
        $limit = $request->limit ?: 10;

        $ratePlans = RatePlan::when($request->room_type_id, function ($q, $room_type_id) {
            return $q->where('room_type_id', $room_type_id);
        })
            ->when($request->enabled !== null, function ($q) use ($request) {
                return $q->where('enabled', $request->enabled);
            })
            ->with('roomType')
            ->paginate($limit);

        return new RatePlanResourceCollection($ratePlans);
    }

    public function store(StoreRatePlanRequest $request): RatePlanResource
    {
        $ratePlan = RatePlan::create($request->all());

        return new RatePlanResource($ratePlan->load('roomType'));
    }

    public function update($id, UpdateRatePlanRequest $request): RatePlanResource
    {
        $ratePlan = RatePlan::find($id);
        $ratePlan->update($request->all());

        return new RatePlanResource($ratePlan->load('roomType'));
    }

    public function destroy(DestroyRatePlanRequest $request): RatePlanResource
    {
        $ratePlan = RatePlan::find($request->rate_plan_id);
        $ratePlan->delete();

        return new RatePlanResource($ratePlan);
    }
}
