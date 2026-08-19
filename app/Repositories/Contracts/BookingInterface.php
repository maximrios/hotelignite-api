<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;

interface BookingInterface
{
    public function all(Request $request);

    public function find($token);

    public function store($request);

    public function update($request);
}
