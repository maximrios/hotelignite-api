<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pricing\BulkRatesRequest;
use App\Http\Requests\Pricing\UpdateOccupancyPricingRequest;
use App\Http\Requests\Pricing\UpsertRatePlanRequest;
use App\Repositories\PricingRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/** Tarifas del PMS: grilla, edición masiva, ocupación y planes. Ver PricingRepository. */
class PricingController extends BaseController
{
    public function __construct(private PricingRepository $pricing) {}

    public function grid(Request $request)
    {
        return response()->json($this->pricing->grid($request));
    }

    public function bulk(BulkRatesRequest $request)
    {
        return response()->json($this->pricing->bulk($request));
    }

    public function occupancy(UpdateOccupancyPricingRequest $request, int $id)
    {
        return response()->json($this->pricing->updateOccupancy($id, $request));
    }

    public function storePlan(UpsertRatePlanRequest $request)
    {
        return response()->json($this->pricing->storePlan($request), 201);
    }

    public function updatePlan(UpsertRatePlanRequest $request, int $id)
    {
        return response()->json($this->pricing->updatePlan($id, $request));
    }

    public function destroyPlan(int $id)
    {
        return response()->json($this->pricing->destroyPlan($id));
    }
}
