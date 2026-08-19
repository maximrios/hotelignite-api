<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyAccommodationPolicyRequest;
use App\Http\Requests\StoreAccommodationPolicyRequest;
use App\Http\Requests\UpdateAccommodationPolicyRequest;
use Illuminate\Http\Request;

interface AccommodationPolicyInterface
{
    public function search(Request $request);

    public function find(int $id);

    public function store(StoreAccommodationPolicyRequest $request);

    public function update(int $id, UpdateAccommodationPolicyRequest $request);

    public function destroy(DestroyAccommodationPolicyRequest $request);
}
