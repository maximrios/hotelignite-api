<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Channel;
use Illuminate\Http\Request;
use App\Http\Resources\V1\ChannelResource;
use App\Repositories\Contracts\ChannelInterface;
use App\Http\Resources\V1\ChannelResourceCollection;

class ChannelRepository implements ChannelInterface
{
    public function all(Request $request)
    {
        $channels = Channel::orderBy('name')->paginate();
        return new ChannelResourceCollection($channels);
    }
}
