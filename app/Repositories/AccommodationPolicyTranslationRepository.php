<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyAccommodationPolicyTranslationRequest;
use App\Http\Requests\StoreAccommodationPolicyTranslationRequest;
use App\Http\Requests\UpdateAccommodationPolicyTranslationRequest;
use App\Http\Resources\V1\AccommodationPolicyTranslationResource;
use App\Http\Resources\V1\AccommodationPolicyTranslationResourceCollection;
use App\Models\AccommodationPolicyTranslation;
use App\Repositories\Contracts\AccommodationPolicyTranslationInterface;
use Illuminate\Http\Request;

class AccommodationPolicyTranslationRepository implements AccommodationPolicyTranslationInterface
{
    public function search(Request $request): AccommodationPolicyTranslationResourceCollection
    {
        $limit = $request->limit ?? 10;
        $offset = $request->offset ?? 0;

        $query = AccommodationPolicyTranslation::query()
            ->when($request->policy_id, fn ($q, $id) => $q->where('policy_id', $id))
            ->when($request->language_id, fn ($q, $id) => $q->where('language_id', $id));

        $results = $query->offset($offset)->limit($limit)->paginate($limit);

        return new AccommodationPolicyTranslationResourceCollection($results);
    }

    public function find(int $id): AccommodationPolicyTranslationResource
    {
        $translation = AccommodationPolicyTranslation::findOrFail($id);

        return new AccommodationPolicyTranslationResource($translation);
    }

    public function store(StoreAccommodationPolicyTranslationRequest $request): AccommodationPolicyTranslationResource
    {
        $translation = AccommodationPolicyTranslation::create($request->validated());

        return new AccommodationPolicyTranslationResource($translation);
    }

    public function update(int $id, UpdateAccommodationPolicyTranslationRequest $request): AccommodationPolicyTranslationResource
    {
        $translation = AccommodationPolicyTranslation::findOrFail($id);
        $translation->update($request->validated());

        return new AccommodationPolicyTranslationResource($translation);
    }

    public function destroy(DestroyAccommodationPolicyTranslationRequest $request): AccommodationPolicyTranslationResource
    {
        $translation = AccommodationPolicyTranslation::findOrFail($request->accommodation_policy_translation_id);
        $translation->delete();

        return new AccommodationPolicyTranslationResource($translation);
    }
}
