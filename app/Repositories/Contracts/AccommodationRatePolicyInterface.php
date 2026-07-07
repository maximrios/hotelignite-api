<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccommodationRatePolicyRequest;
use App\Http\Requests\UpdateAccommodationRatePolicyRequest;
use App\Http\Requests\DestroyAccommodationRatePolicyRequest;

interface AccommodationRatePolicyInterface
{
    public function search(Request $request);
    public function find(int $id);
    public function store(StoreAccommodationRatePolicyRequest $request);
    public function update(int $id, UpdateAccommodationRatePolicyRequest $request);
    public function destroy(DestroyAccommodationRatePolicyRequest $request);
}
