<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyRoomTypeServiceRequest;
use App\Http\Requests\StoreRoomTypeServiceRequest;
use App\Http\Requests\UpdateRoomTypeServiceRequest;
use Illuminate\Http\Request;

interface RoomTypeServiceInterface
{
    public function all(Request $request);

    public function find($id);

    public function search($request);

    public function update($id, UpdateRoomTypeServiceRequest $request);

    public function store(StoreRoomTypeServiceRequest $request);

    public function destroy(DestroyRoomTypeServiceRequest $request);
}
