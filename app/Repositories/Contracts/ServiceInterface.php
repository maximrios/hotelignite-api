<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;

interface ServiceInterface
{
    public function all(Request $request);
}
