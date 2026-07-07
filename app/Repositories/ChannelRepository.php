<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Channel;
use Illuminate\Http\Request;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Http\Resources\V1\ChannelResource;
use App\Repositories\Contracts\ChannelInterface;
use App\Http\Resources\V1\ChannelResourceCollection;

class ChannelRepository implements ChannelInterface
{
    public function all(Request $request)
    {
        $limit = $request->limit ?: 15;

        $channels = Channel::when($request->business_type, fn ($q, $v) => $q->where('business_type', $v))
                           ->when($request->connection_type, fn ($q, $v) => $q->where('connection_type', $v))
                           ->when($request->enabled !== null, fn ($q) => $q->where('enabled', $request->enabled))
                           ->when($request->ota !== null, fn ($q) => $q->where('ota', $request->ota))
                           ->orderBy('name')
                           ->paginate($limit);

        return new ChannelResourceCollection($channels);
    }

    public function find($id)
    {
        $channel = Channel::findOrFail($id);
        return new ChannelResource($channel);
    }

    public function store(StoreChannelRequest $request): ChannelResource
    {
        $channel = Channel::create($request->all());
        return new ChannelResource($channel);
    }

    public function update($id, UpdateChannelRequest $request): ChannelResource
    {
        $channel = Channel::findOrFail($id);
        $channel->update($request->all());
        return new ChannelResource($channel);
    }

    public function destroy($id): ChannelResource
    {
        $channel = Channel::findOrFail($id);
        $channel->delete();
        return new ChannelResource($channel);
    }
}
