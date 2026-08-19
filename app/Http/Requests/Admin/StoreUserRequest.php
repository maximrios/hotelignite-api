<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `platform` + UserPolicy
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'user_type' => ['required', Rule::in(User::TYPES)],

            // `required_if` sólo exige el id del tipo que corresponde; el sobrante
            // se descarta en tenancyAttributes(), no se rechaza, para que el CRM
            // pueda mandar el formulario completo al cambiar de tipo.
            'account_id' => ['required_if:user_type,'.User::TYPE_ACCOUNT, 'nullable', 'integer', 'exists:accounts,id'],
            'client_id' => ['required_if:user_type,'.User::TYPE_CLIENT, 'nullable', 'integer', 'exists:clients,id'],

            // Acotamiento opcional del user `account` a alojamientos puntuales.
            // Vacío = ve toda su cuenta. La pertenencia a la cuenta se valida en
            // withValidator, no acá: `exists` sólo garantiza que el alojamiento
            // existe, no que sea de *esta* cuenta.
            'accommodation_ids' => ['sometimes', 'array'],
            'accommodation_ids.*' => ['integer', 'distinct', 'exists:accommodations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required_if' => 'Un usuario de tipo account requiere un account_id.',
            'client_id.required_if' => 'Un usuario de tipo client requiere un client_id.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            AccommodationScopeRule::validate(
                $validator,
                $this->accommodationIds(),
                $this->input('user_type'),
                $this->integer('account_id'),
            );
        });
    }

    /**
     * Ids de alojamiento a los que acotar, ya validados. Vacío si no vinieron o
     * si el user no es `account` (los otros tipos no llevan acotamiento).
     *
     * @return array<int, int>
     */
    public function accommodationIds(): array
    {
        if ($this->input('user_type') !== User::TYPE_ACCOUNT) {
            return [];
        }

        return array_map('intval', $this->input('accommodation_ids', []));
    }

    /**
     * Atributos listos para persistir, con la tenencia ya normalizada.
     *
     * @return array<string, mixed>
     */
    public function tenancyAttributes(): array
    {
        $validated = $this->validated();

        return array_merge(
            ['name' => $validated['name'], 'email' => $validated['email']],
            User::normalizeTenancy($validated['user_type'], $validated)
        );
    }
}
