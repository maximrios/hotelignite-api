<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\BookingRequest;
use Illuminate\Http\Request;

interface ReservationInterface
{
    public function all(Request $request);
}
