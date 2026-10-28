<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'requires_inspection' => ['required', 'boolean'],
        ];
    }
}
