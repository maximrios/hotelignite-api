<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Repositories\Contracts\ChannelInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class ChannelController extends BaseController
{
    protected ChannelInterface $channelInterface;

    public function __construct(ChannelInterface $channelInterface)
    {
        $this->channelInterface = $channelInterface;
    }

    public function index(Request $request)
    {
        return response()->json($this->channelInterface->all($request), 200);
    }

    public function show($channel)
    {
        return response()->json($this->channelInterface->find($channel), 200);
    }

    public function store(StoreChannelRequest $request)
    {
        return response()->json($this->channelInterface->store($request), 201);
    }

    public function update(UpdateChannelRequest $request, $channel)
    {
        return response()->json($this->channelInterface->update($channel, $request), 200);
    }

    public function destroy($channel)
    {
        $deleted = $this->channelInterface->destroy($channel);
        return response()->json([
            'message' => 'Channel deleted successfully',
            'data'    => $deleted,
        ], 200);
    }
}
