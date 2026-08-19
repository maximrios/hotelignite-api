<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyAccommodationPolicyRequest;
use App\Http\Requests\StoreAccommodationPolicyRequest;
use App\Http\Requests\UpdateAccommodationPolicyRequest;
use App\Http\Resources\V1\AccommodationPolicyResource;
use App\Http\Resources\V1\AccommodationPolicyResourceCollection;
use App\Models\AccommodationPolicy;
use App\Repositories\Contracts\AccommodationPolicyInterface;
use Illuminate\Http\Request;

class AccommodationPolicyRepository implements AccommodationPolicyInterface
{
    public function search(Request $request): AccommodationPolicyResourceCollection
    {
        $limit = $request->limit ?? 10;
        $offset = $request->offset ?? 0;

        $query = AccommodationPolicy::with(['accommodation', 'translations'])
            ->when($request->accommodation_id, fn ($q, $id) => $q->where('accommodation_id', $id));

        $results = $query->offset($offset)->limit($limit)->paginate($limit);

        return new AccommodationPolicyResourceCollection($results);
    }

    public function find(int $id): AccommodationPolicyResource
    {
        $policy = AccommodationPolicy::with(['accommodation', 'translations'])->findOrFail($id);

        return new AccommodationPolicyResource($policy);
    }

    public function store(StoreAccommodationPolicyRequest $request): AccommodationPolicyResource
    {
        $policy = AccommodationPolicy::create($request->validated());
        $policy->load(['accommodation', 'translations']);

        return new AccommodationPolicyResource($policy);
    }

    public function update(int $id, UpdateAccommodationPolicyRequest $request): AccommodationPolicyResource
    {
        $policy = AccommodationPolicy::findOrFail($id);
        $policy->update($request->validated());
        $policy->load(['accommodation', 'translations']);

        return new AccommodationPolicyResource($policy);
    }

    public function destroy(DestroyAccommodationPolicyRequest $request): AccommodationPolicyResource
    {
        $policy = AccommodationPolicy::findOrFail($request->accommodation_policy_id);
        $policy->delete();

        return new AccommodationPolicyResource($policy);
    }
}
