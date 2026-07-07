<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'rate_plan_id' => $this->rate_plan_id,
            'date' => $this->date?->toDateString(),
            'price' => $this->price,
            'price_decimal' => $this->price_decimal,
            'currency' => $this->currency,
            'min_stay' => $this->min_stay,
            'max_stay' => $this->max_stay,
            'rate_plan' => $this->whenLoaded('ratePlan', function () {
                return [
                    'id' => $this->ratePlan->id,
                    'name' => $this->ratePlan->name,
                    'cancellation' => $this->ratePlan->cancellation,
                ];
            }),
        ];
    }
}
