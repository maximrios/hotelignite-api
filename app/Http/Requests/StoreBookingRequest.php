<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'accommodation_id'   => ['sometimes'],
            'room_id'            => ['sometimes'],
            'tour_id'            => ['sometimes'],
            'adults'             => ['required', 'integer', 'min:1'],
            'childrens'          => ['required', 'integer', 'min:0'],
            'checkin'            => ['required', 'date_format:Y-m-d'],
            'checkout'           => ['sometimes', 'date_format:Y-m-d', 'after:checkin'],
            'availability_token' => ['required', 'uuid'],
        ];
    }
}
