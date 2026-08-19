<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyAccommodationRequest;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use Illuminate\Http\Request;

interface AccommodationInterface
{
    public function all(Request $request);

    public function find($id);

    public function search($request);

    public function update($id, UpdateAccommodationRequest $request);

    public function store(StoreAccommodationRequest $request);

    public function destroy(DestroyAccommodationRequest $request);
}
