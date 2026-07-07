<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRoomTypeRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['sometimes', 'integer', 'exists:accommodations,id'],
            'category_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string'],
        ];
    }
}







