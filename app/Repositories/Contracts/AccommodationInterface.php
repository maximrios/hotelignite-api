<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Requests\DestroyAccommodationRequest;

interface AccommodationInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);

    public function update($id, UpdateAccommodationRequest $request);
    public function store(StoreAccommodationRequest $request);
    public function destroy(DestroyAccommodationRequest $request);
}
