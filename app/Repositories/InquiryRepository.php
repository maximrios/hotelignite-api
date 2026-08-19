<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\InquiryResource;
use App\Models\Inquiry;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\InquiryInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InquiryRepository implements InquiryInterface
{
    use ScopesToAccommodation;

    public function all(Request $request)
    {
        $inquiries = Inquiry::visibleTo($this->tenant())
            ->when(
                $request->filled('accommodation_id'),
                fn ($query) => $query->where('accommodation_id', $request->input('accommodation_id'))
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 25));

        return InquiryResource::collection($inquiries);
    }

    public function store($request)
    {
        $checkin = Carbon::createFromFormat('d/m/Y', $request->checkin)->format('Y-m-d');
        $checkout = $request->checkout
            ? Carbon::createFromFormat('d/m/Y', $request->checkout)->format('Y-m-d')
            : null;

        $inquiry = new Inquiry();
        $inquiry->accommodation_id = $request->accommodation_id;
        $inquiry->name = $request->name;
        $inquiry->lastname = $request->lastname;
        $inquiry->email = $request->email;
        $inquiry->phone = $request->phone;
        $inquiry->adults = $request->adults;
        $inquiry->childrens = $request->childrens;
        $inquiry->checkin = $checkin;
        $inquiry->checkout = $checkout;
        $inquiry->message = $request->message;
        $inquiry->save();

        return new InquiryResource($inquiry);
    }
}
