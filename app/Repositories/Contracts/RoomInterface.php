<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyRoomRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use Illuminate\Http\Request;

interface RoomInterface
{
    public function all(Request $request);

    public function find($id);

    public function search($request);

    public function store(StoreRoomRequest $request);

    public function update($id, UpdateRoomRequest $request);

    public function destroy(DestroyRoomRequest $request);
}
