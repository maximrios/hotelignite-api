<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccommodationDescriptionRequest;
use App\Http\Requests\UpdateAccommodationDescriptionRequest;

interface AccommodationDescriptionInterface
{
    public function all(Request $request);
    public function find($id);
    public function store(StoreAccommodationDescriptionRequest $request);
    public function update($id, UpdateAccommodationDescriptionRequest $request);
    public function destroy(Request $request);
}
