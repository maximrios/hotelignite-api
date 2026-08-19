<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyAccommodationRatePolicyRequest;
use App\Http\Requests\StoreAccommodationRatePolicyRequest;
use App\Http\Requests\UpdateAccommodationRatePolicyRequest;
use App\Http\Resources\V1\AccommodationRatePolicyResource;
use App\Http\Resources\V1\AccommodationRatePolicyResourceCollection;
use App\Models\AccommodationRatePolicy;
use App\Repositories\Contracts\AccommodationRatePolicyInterface;
use Illuminate\Http\Request;

class AccommodationRatePolicyRepository implements AccommodationRatePolicyInterface
{
    public function search(Request $request): AccommodationRatePolicyResourceCollection
    {
        $limit = $request->limit ?? 10;
        $offset = $request->offset ?? 0;

        $query = AccommodationRatePolicy::with('accommodation')
            ->when($request->accommodation_id, fn ($q, $id) => $q->where('accommodation_id', $id))
            ->when($request->rate_type, fn ($q, $v) => $q->where('rate_type', $v));

        $results = $query->offset($offset)->limit($limit)->paginate($limit);

        return new AccommodationRatePolicyResourceCollection($results);
    }

    public function find(int $id): AccommodationRatePolicyResource
    {
        $ratePolicy = AccommodationRatePolicy::with('accommodation')->findOrFail($id);

        return new AccommodationRatePolicyResource($ratePolicy);
    }

    public function store(StoreAccommodationRatePolicyRequest $request): AccommodationRatePolicyResource
    {
        $ratePolicy = AccommodationRatePolicy::create($request->validated());
        $ratePolicy->load('accommodation');

        return new AccommodationRatePolicyResource($ratePolicy);
    }

    public function update(int $id, UpdateAccommodationRatePolicyRequest $request): AccommodationRatePolicyResource
    {
        $ratePolicy = AccommodationRatePolicy::findOrFail($id);
        $ratePolicy->update($request->validated());
        $ratePolicy->load('accommodation');

        return new AccommodationRatePolicyResource($ratePolicy);
    }

    public function destroy(DestroyAccommodationRatePolicyRequest $request): AccommodationRatePolicyResource
    {
        $ratePolicy = AccommodationRatePolicy::findOrFail($request->accommodation_rate_policy_id);
        $ratePolicy->delete();

        return new AccommodationRatePolicyResource($ratePolicy);
    }
}
