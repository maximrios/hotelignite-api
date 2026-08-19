<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyAccommodationRatePolicyRequest;
use App\Http\Requests\StoreAccommodationRatePolicyRequest;
use App\Http\Requests\UpdateAccommodationRatePolicyRequest;
use Illuminate\Http\Request;

interface AccommodationRatePolicyInterface
{
    public function search(Request $request);

    public function find(int $id);

    public function store(StoreAccommodationRatePolicyRequest $request);

    public function update(int $id, UpdateAccommodationRatePolicyRequest $request);

    public function destroy(DestroyAccommodationRatePolicyRequest $request);
}
