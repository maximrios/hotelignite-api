<?php

namespace App\Http\Requests\ClientPanel;

use App\Rules\CloudinaryImageUrl;
use Illuminate\Foundation\Http\FormRequest;

/** Logo del client. `null` en los dos campos lo quita. */
class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `client.user`
    }

    public function rules(): array
    {
        return [
            'logo_url' => ['present', 'nullable', 'string', 'max:500', new CloudinaryImageUrl],
            'logo_public_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
