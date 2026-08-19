<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `platform` + UserPolicy
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->targetUser()->id)],

            // Opcional: si no viene (o viene vacío) la contraseña no se toca.
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],

            'user_type' => ['sometimes', 'required', Rule::in(User::TYPES)],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'],

            // Acotamiento por alojamiento. Si no viene, el pivote no se toca
            // (salvo que el PATCH cambie de cuenta/tipo — eso lo resuelve el
            // controller). La pertenencia a la cuenta se valida en withValidator.
            'accommodation_ids' => ['sometimes', 'array'],
            'accommodation_ids.*' => ['integer', 'distinct', 'exists:accommodations,id'],
        ];
    }

    /**
     * La tenencia se valida sobre el estado *resultante*, no sobre el payload:
     * un PATCH puede cambiar sólo el tipo (heredando el id que ya tenía) o sólo
     * el id (manteniendo el tipo). Lo que no puede es dejar un account/client
     * sin su id correspondiente.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $resulting = $this->resultingTenancy();

            if ($resulting['user_type'] === User::TYPE_ACCOUNT && $resulting['account_id'] === null) {
                $validator->errors()->add('account_id', 'Un usuario de tipo account requiere un account_id.');
            }

            if ($resulting['user_type'] === User::TYPE_CLIENT && $resulting['client_id'] === null) {
                $validator->errors()->add('client_id', 'Un usuario de tipo client requiere un client_id.');
            }

            // Degradar al último platform deja la plataforma sin acceso a
            // /api/admin/v1, igual que borrarlo — y sin forma de revertirlo por
            // API. El guard de borrado no cubre este camino: un platform puede
            // degradarse a sí mismo con un PATCH.
            if ($this->wouldRemoveLastPlatform($resulting['user_type'])) {
                $validator->errors()->add(
                    'user_type',
                    'No se puede cambiar el tipo del último usuario de plataforma: dejaría la plataforma sin administradores.'
                );
            }

            // El acotamiento se valida contra la cuenta *resultante*, no la
            // actual: un PATCH puede mover al user de cuenta y de alojamientos a
            // la vez, y los nuevos ids tienen que ser de la cuenta nueva.
            if ($this->has('accommodation_ids')) {
                AccommodationScopeRule::validate(
                    $validator,
                    array_map('intval', $this->input('accommodation_ids', [])),
                    $resulting['user_type'],
                    $resulting['account_id'],
                );
            }
        });
    }

    private function wouldRemoveLastPlatform(string $resultingType): bool
    {
        $user = $this->targetUser();

        if (! $user->isPlatform() || $resultingType === User::TYPE_PLATFORM) {
            return false;
        }

        return User::where('user_type', User::TYPE_PLATFORM)
            ->whereKeyNot($user->id)
            ->doesntExist();
    }

    /**
     * Estado de tenencia que quedaría al aplicar este PATCH: se parte de lo que
     * el usuario ya tiene y se pisa con lo que trae el payload, normalizando al
     * final para no arrastrar los ids del tipo anterior.
     *
     * @return array{user_type: string, account_id: int|null, client_id: int|null}
     */
    public function resultingTenancy(): array
    {
        $user = $this->targetUser();

        $userType = $this->input('user_type', $user->user_type);

        $merged = [
            'account_id' => $this->has('account_id') ? $this->input('account_id') : $user->account_id,
            'client_id' => $this->has('client_id') ? $this->input('client_id') : $user->client_id,
        ];

        return User::normalizeTenancy($userType, $merged);
    }

    /**
     * Atributos listos para persistir. Sólo incluye los campos presentes en el
     * payload; la tenencia se agrega completa cuando el PATCH la toca.
     *
     * @return array<string, mixed>
     */
    public function tenancyAttributes(): array
    {
        $attributes = [];

        foreach (['name', 'email'] as $field) {
            if ($this->has($field)) {
                $attributes[$field] = $this->validated()[$field];
            }
        }

        if ($this->has('user_type') || $this->has('account_id') || $this->has('client_id')) {
            $attributes = array_merge($attributes, $this->resultingTenancy());
        }

        return $attributes;
    }

    /** ¿El PATCH trae el acotamiento por alojamiento? Si no, el pivote no se toca. */
    public function touchesAccommodationScope(): bool
    {
        return $this->has('accommodation_ids');
    }

    /**
     * Ids de alojamiento a sincronizar. Vacío si la tenencia resultante no es
     * `account` (los otros tipos no llevan acotamiento).
     *
     * @return array<int, int>
     */
    public function accommodationIds(): array
    {
        if ($this->resultingTenancy()['user_type'] !== User::TYPE_ACCOUNT) {
            return [];
        }

        return array_map('intval', $this->input('accommodation_ids', []));
    }

    public function targetUser(): User
    {
        return $this->route('user');
    }
}
