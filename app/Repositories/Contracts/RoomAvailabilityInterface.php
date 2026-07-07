<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRoomAvailabilityRequest;
use App\Http\Requests\UpdateRoomAvailabilityRequest;
use App\Http\Requests\DestroyRoomAvailabilityRequest;

interface RoomAvailabilityInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);
    public function store(StoreRoomAvailabilityRequest $request);
    public function update($id, UpdateRoomAvailabilityRequest $request);
    public function destroy(DestroyRoomAvailabilityRequest $request);
}
