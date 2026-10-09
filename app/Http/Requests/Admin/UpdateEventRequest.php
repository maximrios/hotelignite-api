<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Edición parcial (PUT y PATCH): sólo se validan los campos presentes. */
class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = EventRules::for($this->event(), $this->input('source'));

        return array_map(fn (array $rule) => ['sometimes', ...$rule], $rules);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => EventRules::after($validator, $this->all(), $this->event())];
    }

    private function event(): ?Event
    {
        $event = $this->route('event');

        return $event instanceof Event ? $event : null;
    }
}
