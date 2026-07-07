<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreInquiryRequest;
use App\Repositories\Contracts\InquiryInterface;
use Illuminate\Routing\Controller as BaseController;

class InquiryController extends BaseController
{
    protected InquiryInterface $inquiryInterface;

    public function __construct(InquiryInterface $inquiryInterface)
    {
        $this->inquiryInterface = $inquiryInterface;
    }

    public function store(StoreInquiryRequest $request)
    {
        return response()->json($this->inquiryInterface->store($request), 201);
    }
}
