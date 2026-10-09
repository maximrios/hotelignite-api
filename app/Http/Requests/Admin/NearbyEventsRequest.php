<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class NearbyEventsRequest extends FormRequest
{
    /** La tenencia la resuelve el controller con `AccommodationPolicy@view`. */
    public function authorize(): bool
    {
        return true;
    }

    /** Ventana de hasta 90 días: es "durante tu estadía", no la agenda del año. */
    public function rules(): array
    {
        $from = $this->input('from');
        $max = is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            ? CarbonImmutable::parse($from)->addDays(90)->toDateString()
            : '2999-12-31';

        return [
            'from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from', 'before_or_equal:'.$max],
            'radius_m' => ['sometimes', 'integer', 'min:500', 'max:50000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
