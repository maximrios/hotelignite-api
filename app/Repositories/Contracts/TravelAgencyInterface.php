<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;

interface TravelAgencyInterface
{
    public function all(Request $request);

    public function find($id);
}
