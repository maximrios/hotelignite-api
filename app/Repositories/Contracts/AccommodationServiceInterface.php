<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccommodationServiceRequest;
use App\Http\Requests\UpdateAccommodationServiceRequest;
use App\Http\Requests\DestroyAccommodationServiceRequest;

interface AccommodationServiceInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);

    public function update($id, UpdateAccommodationServiceRequest $request);
    public function store(StoreAccommodationServiceRequest $request);
    public function destroy(DestroyAccommodationServiceRequest $request);
}




