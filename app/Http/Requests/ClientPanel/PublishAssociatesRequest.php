<?php

namespace App\Http\Requests\ClientPanel;

use App\Support\AssociateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Habilitar o quitar de la web un lote de asociados del mismo tipo. Contrato
 * polimórfico desde el día uno: `entity_type` + `entity_ids`.
 */
class PublishAssociatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `client.user`
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(AssociateType::all())],
            'entity_ids' => ['required', 'array', 'min:1', 'max:200'],
            'entity_ids.*' => ['integer', 'min:1'],
            'published' => ['required', 'boolean'],
        ];
    }
}
