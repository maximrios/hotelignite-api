<?php

namespace App\Http\Requests\Booking;

use App\Rules\Turnstile;
use App\Repositories\BookingEngineRepository;
use Illuminate\Foundation\Http\FormRequest;

/** Fechas y ocupación: lo comparten la búsqueda y el hold. */
class BookingStayRequest extends FormRequest
{
    private const MAX_NIGHTS = 30;

    public function authorize(): bool
    {
        return true;
    }

    /** Una sola habitación se puede pedir con los campos sueltos. */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('post') && ! $this->has('items') && $this->filled('room_type_id')) {
            $this->merge(['items' => [[
                'room_type_id' => $this->input('room_type_id'),
                'rate_plan_id' => $this->input('rate_plan_id'),
            ]]]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'checkin' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'checkout' => ['required', 'date_format:Y-m-d', 'after:checkin'],
            'adults' => ['required', 'integer', 'min:1', 'max:'.BookingEngineRepository::MAX_ADULTS],
            'children' => ['sometimes', 'integer', 'min:0', 'max:'.BookingEngineRepository::MAX_CHILDREN],
            'rooms' => ['sometimes', 'integer', 'min:1', 'max:'.BookingEngineRepository::MAX_ROOMS],
            'items' => [$this->isMethod('post') ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.BookingEngineRepository::MAX_ROOMS],
            'items.*.room_type_id' => ['required', 'integer'],
            'items.*.rate_plan_id' => ['required', 'integer'],
            // utm_* con los que llegó al motor. Se guardan tal cual, recortados.
            'attribution' => ['sometimes', 'nullable', 'array:source,medium,campaign'],
            'attribution.*' => ['nullable', 'string', 'max:100'],
            'cf-turnstile-response' => $this->isMethod('post') && filled(config('services.turnstile.secret_key'))
                ? ['bail', 'required', 'string', 'max:2048', new Turnstile($this->header('X-Engine-Client-Ip', $this->ip()))]
                : ['nullable'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $nights = (int) strtotime((string) $this->input('checkout')) - (int) strtotime((string) $this->input('checkin'));

            if ($nights / 86400 > self::MAX_NIGHTS) {
                $validator->errors()->add('checkout', 'La estadía online no puede superar las '.self::MAX_NIGHTS.' noches.');
            }
        }];
    }
}
