<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;

interface ChannelInterface
{
    public function all(Request $request);
    public function find($id);
    public function store(StoreChannelRequest $request);
    public function update($id, UpdateChannelRequest $request);
    public function destroy($id);
}
