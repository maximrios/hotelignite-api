<?php

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;

interface InquiryInterface
{
    public function all(Request $request);

    public function store($request);
}
