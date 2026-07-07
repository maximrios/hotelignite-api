<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreAccommodationPolicyTranslationRequest;
use App\Http\Requests\UpdateAccommodationPolicyTranslationRequest;
use App\Http\Requests\DestroyAccommodationPolicyTranslationRequest;
use App\Http\Requests\SearchAccommodationPolicyTranslationRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationPolicyTranslationInterface;

class AccommodationPolicyTranslationController extends BaseController
{
    protected AccommodationPolicyTranslationInterface $repo;

    public function __construct(AccommodationPolicyTranslationInterface $repo)
    {
        $this->repo = $repo;
    }

    public function index(SearchAccommodationPolicyTranslationRequest $request)
    {
        return response()->json($this->repo->search($request), 200);
    }

    public function show(int $id)
    {
        return response()->json($this->repo->find($id), 200);
    }

    public function store(StoreAccommodationPolicyTranslationRequest $request)
    {
        $translation = $this->repo->store($request);
        return response()->json([
            'message' => 'Policy translation created successfully',
            'data'    => $translation,
        ], 201);
    }

    public function update(UpdateAccommodationPolicyTranslationRequest $request, int $id)
    {
        $translation = $this->repo->update($id, $request);
        return response()->json([
            'message' => 'Policy translation updated successfully',
            'data'    => $translation,
        ], 200);
    }

    public function destroy(DestroyAccommodationPolicyTranslationRequest $request)
    {
        $translation = $this->repo->destroy($request);
        return response()->json([
            'message' => 'Policy translation deleted successfully',
            'data'    => $translation,
        ], 200);
    }
}
