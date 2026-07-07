<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'active'          => $this->active,
            'test'            => $this->test,
            'agreement'       => $this->agreement,
            'expiration_date' => $this->expiration_date?->toDateString(),
            'comments'        => $this->comments,
            'token'           => $this->token,
            'plan_id'         => $this->plan_id,
            'account_type_id' => $this->account_type_id,
            'plan'            => $this->whenLoaded('plan', fn() => ['id' => $this->plan->id, 'name' => $this->plan->name]),
            'account_type'    => $this->whenLoaded('accountType', fn() => ['id' => $this->accountType->id, 'name' => $this->accountType->name]),
            'accommodations_count' => $this->whenLoaded('accommodations', fn() => $this->accommodations->count(), fn() => $this->accommodations()->count()),
            'created_at'      => $this->created_at?->toDateString(),
        ];
    }
}
