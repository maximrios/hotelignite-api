<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
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
            'accommodation_id' => 'sometimes',
            'room_id' => 'sometimes',
            'tour_id' => 'sometimes',
            'adults' => 'require',
            'childrens' => 'require',
            'checkin' => 'require',
            'checkout' => 'sometimes',
        ];
    }
}
