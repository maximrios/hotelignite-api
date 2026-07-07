<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyAccommodationPolicyTranslationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_policy_translation_id' => ['required', 'integer', 'exists:accommodation_policy_translations,id'],
        ];
    }
}
