<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;

interface ChannelInterface
{
    public function all(Request $request);
}
