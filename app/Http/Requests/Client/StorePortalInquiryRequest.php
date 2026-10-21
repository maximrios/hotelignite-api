<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consulta que manda un portal (`client/v1/inquiries`) en nombre de un viajero.
 * Es sobre un alojamiento o sobre una experiencia, nunca las dos. Fechas ISO.
 *
 * - Alojamiento: `checkin` obligatorio, `checkout` opcional.
 * - Experiencia: `checkin` es la fecha deseada, opcional; no hay `checkout`.
 */
class StorePortalInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accommodation_id' => ['required_without:experience_id', 'prohibits:experience_id', 'integer'],
            'experience_id' => ['required_without:accommodation_id', 'integer'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'lastname' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'adults' => ['required', 'integer', 'min:1', 'max:100'],
            'childrens' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'checkin' => ['required_with:accommodation_id', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'checkout' => ['nullable', 'date_format:Y-m-d', 'after:checkin', 'prohibits:experience_id'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
