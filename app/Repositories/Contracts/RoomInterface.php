<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Requests\DestroyRoomRequest;

interface RoomInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);
    public function store(StoreRoomRequest $request);
    public function update($id, UpdateRoomRequest $request);
    public function destroy(DestroyRoomRequest $request);
}
