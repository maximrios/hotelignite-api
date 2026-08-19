<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class InquiryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'name' => $this->name,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'adults' => $this->adults,
            'childrens' => $this->childrens,
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'message' => $this->message,
            'status' => $this->status ?? 'new',
            'created_at' => $this->created_at,
        ];
    }
}
