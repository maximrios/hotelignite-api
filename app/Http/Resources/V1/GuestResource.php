<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class GuestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'identity' => $this->identity,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
