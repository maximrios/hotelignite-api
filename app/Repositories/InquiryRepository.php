<?php

declare(strict_types=1);

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Inquiry;
use App\Http\Resources\V1\InquiryResource;
use App\Repositories\Contracts\InquiryInterface;

class InquiryRepository implements InquiryInterface
{
    public function store($request)
    {
        $checkin = Carbon::createFromFormat('d/m/Y', $request->checkin)->format('Y-m-d');
        $checkout = $request->checkout
            ? Carbon::createFromFormat('d/m/Y', $request->checkout)->format('Y-m-d')
            : null;

        $inquiry = new Inquiry();
        $inquiry->accommodation_id = $request->accommodation_id;
        $inquiry->name             = $request->name;
        $inquiry->lastname         = $request->lastname;
        $inquiry->email            = $request->email;
        $inquiry->phone            = $request->phone;
        $inquiry->adults           = $request->adults;
        $inquiry->childrens        = $request->childrens;
        $inquiry->checkin          = $checkin;
        $inquiry->checkout         = $checkout;
        $inquiry->message          = $request->message;
        $inquiry->save();

        return new InquiryResource($inquiry);
    }
}
