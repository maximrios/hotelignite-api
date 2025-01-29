<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;

interface CityInterface
{
    public function all(Request $request);
}
