<?php

namespace App\Http\Requests;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => [
                'sometimes', 'string', 'min:3', 'max:255',
                Rule::unique('services', 'name')->ignore($this->serviceId()),
            ],
            'type' => ['sometimes', Rule::in(['general', 'room', 'bathroom', 'accessibility', 'kitchen'])],
            // Alias público de la columna `ico`; el mapeo lo hace el modelo.
            'icon' => ['nullable', 'string', 'max:255'],
            'is_highlighted' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * El id de la fila que se está editando, para excluirla del `unique`.
     *
     * `admin/v1` usa route-model binding (`{service}` llega como modelo) y el
     * `/api/v1` legacy pasa el id crudo por `{id}`. Sirve para los dos.
     */
    private function serviceId(): ?int
    {
        $bound = $this->route('service') ?? $this->route('id');

        if ($bound instanceof Service) {
            return $bound->id;
        }

        return $bound === null ? null : (int) $bound;
    }
}
