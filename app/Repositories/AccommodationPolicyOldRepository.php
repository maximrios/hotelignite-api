<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use App\Models\AccommodationPolicyOld;
use App\Http\Requests\StoreAccommodationPolicyOldRequest;
use App\Http\Resources\V1\AccommodationPolicyOldResource;
use App\Http\Requests\DestroyAccommodationPolicyOldRequest;
use App\Http\Requests\UpdateAccommodationPolicyOldRequest;
use App\Repositories\Contracts\AccommodationPolicyOldInterface;
use App\Http\Resources\V1\AccommodationPolicyOldResourceCollection;

class AccommodationPolicyOldRepository implements AccommodationPolicyOldInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $accommodationPolicies = AccommodationPolicyOld::when($request->accommodation_id, function ($q, $accommodation_id) {
                                return $q->where('accommodation_id', $accommodation_id);
                            })
                            ->when($request->policy_id, function ($q, $policy_id) {
                                return $q->where('policy_id', $policy_id);
                            })
                            ->when($request->language_id, function ($q, $language_id) {
                                return $q->where('language_id', $language_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->get();

        return new AccommodationPolicyOldResourceCollection($accommodationPolicies);
    }

    public function find($id)
    {
        $accommodationPolicy = AccommodationPolicyOld::find($id);
        return new AccommodationPolicyOldResource($accommodationPolicy);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $accommodationPolicies = AccommodationPolicyOld::when($request->accommodation_id, function ($q, $accommodation_id) {
                                return $q->where('accommodation_id', $accommodation_id);
                            })
                            ->when($request->policy_id, function ($q, $policy_id) {
                                return $q->where('policy_id', $policy_id);
                            })
                            ->when($request->language_id, function ($q, $language_id) {
                                return $q->where('language_id', $language_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->paginate();

        return new AccommodationPolicyOldResourceCollection($accommodationPolicies);
    }

    public function update($id, UpdateAccommodationPolicyOldRequest $request): AccommodationPolicyOldResourceCollection
    {
        $accommodation = Accommodation::find($id);

        if (!$accommodation) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Accommodation not found');
        }

        $updatedPolicies = [];
        foreach ($request->policies as $policyData) {
            $accommodationPolicy = AccommodationPolicyOld::where('accommodation_id', $id)
                ->where('policy_id', $policyData['policy_id'])
                ->where('language_id', $policyData['language_id'])
                ->first();

            if ($accommodationPolicy) {
                $accommodationPolicy->update(['description' => $policyData['description']]);
                $updatedPolicies[] = $accommodationPolicy;
            } else {
                $newPolicy = AccommodationPolicyOld::create([
                    'accommodation_id' => $id,
                    'policy_id' => $policyData['policy_id'],
                    'language_id' => $policyData['language_id'],
                    'description' => $policyData['description'],
                ]);
                $updatedPolicies[] = $newPolicy;
            }
        }

        return new AccommodationPolicyOldResourceCollection(collect($updatedPolicies));
    }

    public function store(StoreAccommodationPolicyOldRequest $request): AccommodationPolicyOldResourceCollection
    {
        $accommodation = Accommodation::find($request->accommodation_id);

        if (!$accommodation) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Accommodation not found');
        }

        $createdPolicies = [];
        foreach ($request->policies as $policyData) {
            $existingPolicy = AccommodationPolicyOld::where('accommodation_id', $request->accommodation_id)
                ->where('policy_id', $policyData['policy_id'])
                ->where('language_id', $policyData['language_id'])
                ->first();

            if (!$existingPolicy) {
                $newPolicy = AccommodationPolicyOld::create([
                    'accommodation_id' => $request->accommodation_id,
                    'policy_id' => $policyData['policy_id'],
                    'language_id' => $policyData['language_id'],
                    'description' => $policyData['description'],
                ]);
                $createdPolicies[] = $newPolicy;
            } else {
                $existingPolicy->update(['description' => $policyData['description']]);
                $createdPolicies[] = $existingPolicy;
            }
        }

        return new AccommodationPolicyOldResourceCollection(collect($createdPolicies));
    }

    public function destroy(DestroyAccommodationPolicyOldRequest $request): AccommodationPolicyOldResource
    {
        $accommodationPolicy = AccommodationPolicyOld::find($request->accommodation_policy_id);
        $accommodationPolicy->delete();
        return new AccommodationPolicyOldResource($accommodationPolicy);
    }
}
