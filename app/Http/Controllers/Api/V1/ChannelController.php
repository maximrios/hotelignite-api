<?php

namespace App\Http\Controllers\Api\V1;

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
        $channels = $this->channelInterface->all($request);
        return response()->json($channels, 200);
    }

    public function show(Request $request)
    {
        return response()->json( $this->channelInterface->find($request->id), 200);
        
    }
}