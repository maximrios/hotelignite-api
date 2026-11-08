<?php

namespace App\Http\Requests\Booking;

use App\Models\Extra;
use App\Support\BookingRequestDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'min:6', 'max:40'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'accepted_terms' => ['accepted'],
            'document_type' => ['nullable', Rule::in(['dni', 'passport', 'other'])],
            'document_number' => ['nullable', 'required_with:document_type', 'string', 'max:30'],
            // ISO 3166 de 2 letras, como en el check-in del PMS.
            'nationality' => ['nullable', 'string', 'size:2', 'alpha'],
            'arrival_time' => ['nullable', 'string', 'max:60'],
            'children_ages' => ['nullable', 'array', 'max:20'],
            'children_ages.*' => ['integer', 'between:0,17'],
            // Extras del hotel (los que ofrece el hold), con cantidad. El precio lo pone la API.
            'extras' => ['nullable', 'array', 'max:20'],
            'extras.*.id' => ['required', 'integer', 'distinct'],
            'extras.*.quantity' => ['required', 'integer', 'min:1', 'max:'.Extra::MAX_QUANTITY],
            'payment_plan' => ['nullable', Rule::in(array_keys(BookingRequestDetails::PAYMENT_PLANS))],
            'payment_method' => ['nullable', Rule::in(array_keys(BookingRequestDetails::PAYMENT_METHODS))],
            'newsletter' => ['nullable', 'boolean'],
            // Cómo reparte a los huéspedes, una entrada por habitación en el orden del hold.
            'occupancy' => ['nullable', 'array', 'max:4'],
            'occupancy.*.adults' => ['required', 'integer', 'min:1', 'max:20'],
            'occupancy.*.children' => ['required', 'integer', 'min:0', 'max:20'],
        ];
    }
}
