<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class ChannelResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'business_type'   => $this->business_type,
            'connection_type' => $this->connection_type,
            'code'            => $this->code,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'web'             => $this->web,
            'ota'             => $this->ota,
            'commission_rate' => $this->commission_rate,
            'enabled'         => $this->enabled,
        ];
    }
}
