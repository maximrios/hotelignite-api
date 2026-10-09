<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `when`: `upcoming` (default) = no terminaron; `past`; `all`. Con `from`/`to`
     * se filtra por ocurrencia en esa ventana, que se acota a un año porque los
     * semanales se resuelven recorriendo los días del cruce.
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(Event::STATUSES)],
            'when' => ['sometimes', Rule::in(['upcoming', 'past', 'all'])],
            'from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from', 'before_or_equal:'.$this->maxTo()],
            'enabled' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    private function maxTo(): string
    {
        $from = $this->input('from');

        return is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            ? CarbonImmutable::parse($from)->addYear()->toDateString()
            : '2999-12-31';
    }
}
