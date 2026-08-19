<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyAccommodationPolicyTranslationRequest;
use App\Http\Requests\StoreAccommodationPolicyTranslationRequest;
use App\Http\Requests\UpdateAccommodationPolicyTranslationRequest;
use Illuminate\Http\Request;

interface AccommodationPolicyTranslationInterface
{
    public function search(Request $request);

    public function find(int $id);

    public function store(StoreAccommodationPolicyTranslationRequest $request);

    public function update(int $id, UpdateAccommodationPolicyTranslationRequest $request);

    public function destroy(DestroyAccommodationPolicyTranslationRequest $request);
}
