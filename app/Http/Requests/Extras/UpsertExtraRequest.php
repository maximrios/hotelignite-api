<?php

namespace App\Http\Requests\Extras;

use App\Models\Extra;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta y edición de un extra desde el PMS. El precio llega en pesos (con decimales). */
class UpsertExtraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'accommodation_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer'],
            'template' => [$this->isMethod('post') ? 'nullable' : 'prohibited', Rule::in(array_keys(Extra::TEMPLATES))],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Foto subida a Cloudinary desde el PMS; null la quita.
            'image_url' => ['nullable', 'string', 'url:https', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'charge_mode' => ['required', Rule::in(Extra::MODES)],
            'max_quantity' => ['required', 'integer', 'min:1', 'max:'.Extra::MAX_QUANTITY],
            'price_includes_tax' => ['sometimes', 'boolean'],
            'requires_confirmation' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Ingresá el nombre del extra.',
            'price.required' => 'Ingresá el precio (0 si no tiene cargo).',
            'price.min' => 'El precio no puede ser negativo.',
            'max_quantity.max' => 'Como máximo '.Extra::MAX_QUANTITY.' por reserva.',
        ];
    }
}
