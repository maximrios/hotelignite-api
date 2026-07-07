<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRoomTypeDescriptionRequest;
use App\Http\Requests\UpdateRoomTypeDescriptionRequest;
use App\Http\Requests\DestroyRoomTypeDescriptionRequest;

interface RoomTypeDescriptionInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);

    public function update($id, UpdateRoomTypeDescriptionRequest $request);
    public function store(StoreRoomTypeDescriptionRequest $request);
    public function destroy(DestroyRoomTypeDescriptionRequest $request);
}

