<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyAccommodationPolicyOldRequest;
use App\Http\Requests\StoreAccommodationPolicyOldRequest;
use App\Http\Requests\UpdateAccommodationPolicyOldRequest;
use Illuminate\Http\Request;

interface AccommodationPolicyOldInterface
{
    public function all(Request $request);

    public function find($id);

    public function search($request);

    public function update($id, UpdateAccommodationPolicyOldRequest $request);

    public function store(StoreAccommodationPolicyOldRequest $request);

    public function destroy(DestroyAccommodationPolicyOldRequest $request);
}
