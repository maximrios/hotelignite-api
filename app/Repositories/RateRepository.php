<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyRateRequest;
use App\Http\Requests\StoreRateRequest;
use App\Http\Requests\UpdateRateRequest;
use App\Http\Resources\V1\RateResource;
use App\Http\Resources\V1\RateResourceCollection;
use App\Models\Rate;
use App\Repositories\Contracts\RateInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RateRepository implements RateInterface
{
    public function all(Request $request)
    {
        $limit = $request->limit ?: 10;
        $offset = $request->offset ?: 0;

        $rates = Rate::when($request->rate_plan_id, function ($q, $rate_plan_id) {
            return $q->where('rate_plan_id', $rate_plan_id);
        })
            ->when($request->date_from, function ($q, $date_from) {
                return $q->where('date', '>=', $date_from);
            })
            ->when($request->date_to, function ($q, $date_to) {
                return $q->where('date', '<=', $date_to);
            })
            ->with('ratePlan')
            ->orderBy('date')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return new RateResourceCollection($rates);
    }

    public function find($id)
    {
        $rate = Rate::with('ratePlan')->find($id);

        return new RateResource($rate);
    }

    public function search($request)
    {
        $limit = $request->limit ?: 10;

        $rates = Rate::when($request->rate_plan_id, function ($q, $rate_plan_id) {
            return $q->where('rate_plan_id', $rate_plan_id);
        })
            ->when($request->date_from, function ($q, $date_from) {
                return $q->where('date', '>=', $date_from);
            })
            ->when($request->date_to, function ($q, $date_to) {
                return $q->where('date', '<=', $date_to);
            })
            ->with('ratePlan')
            ->orderBy('date')
            ->paginate($limit);

        return new RateResourceCollection($rates);
    }

    public function store(StoreRateRequest $request): RateResourceCollection
    {
        // Soporta carga masiva por rango de fechas
        $dateFrom = Carbon::parse($request->date_from);
        $dateTo = Carbon::parse($request->date_to);
        $upserted = [];

        for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
            $rate = Rate::updateOrCreate(
                ['rate_plan_id' => $request->rate_plan_id, 'date' => $date->toDateString()],
                [
                    'price' => $request->price,
                    'currency' => $request->currency ?? 'ARS',
                    'min_stay' => $request->min_stay ?? 1,
                    'max_stay' => $request->max_stay,
                ]
            );
            $upserted[] = $rate;
        }

        return new RateResourceCollection(collect($upserted));
    }

    public function update($id, UpdateRateRequest $request): RateResource
    {
        $rate = Rate::find($id);
        $rate->update($request->all());

        return new RateResource($rate);
    }

    public function destroy(DestroyRateRequest $request): RateResource
    {
        $rate = Rate::find($request->rate_id);
        $rate->delete();

        return new RateResource($rate);
    }
}
