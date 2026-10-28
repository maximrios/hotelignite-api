<?php

namespace App\Http\Requests\Inventory;

use App\Models\RoomTypeBed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un tipo de habitación desde el PMS. Obligatorio para operar
 * y publicar en canales: nombre, código corto, ocupación estándar y máxima,
 * adultos máximos y al menos una configuración de camas.
 */
class UpsertRoomTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,6}$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'standard_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20', 'gte:standard_occupancy'],
            'max_adults' => ['required', 'integer', 'min:1', 'lte:max_occupancy'],
            'max_children' => ['nullable', 'integer', 'min:0', 'lt:max_occupancy'],
            'size' => ['nullable', 'numeric', 'min:1', 'max:9999'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'beds' => ['required', 'array', 'min:1', 'max:8'],
            'beds.*.type' => ['required', Rule::in(RoomTypeBed::TYPES)],
            'beds.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function messages()
    {
        return [
            'name.required' => 'Ingresá el nombre del tipo.',
            'code.required' => 'Ingresá un código corto.',
            'code.regex' => 'El código va de 2 a 6 letras o números, sin espacios.',
            'standard_occupancy.required' => 'Indicá la ocupación estándar.',
            'max_occupancy.required' => 'Indicá la ocupación máxima.',
            'max_occupancy.gte' => 'La máxima no puede ser menor que la estándar.',
            'max_adults.required' => 'Indicá cuántos adultos admite.',
            'max_adults.lte' => 'No puede superar la ocupación máxima.',
            'max_children.lt' => 'Tiene que quedar lugar para al menos un adulto.',
            'beds.required' => 'Cargá al menos una cama.',
            'beds.min' => 'Cargá al menos una cama.',
        ];
    }
}
