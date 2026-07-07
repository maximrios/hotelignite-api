<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchServiceRequest extends FormRequest
{
    
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['sometimes', 'string'],
            'slug' => ['sometimes', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}




