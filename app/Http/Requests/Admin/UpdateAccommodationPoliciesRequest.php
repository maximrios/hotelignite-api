<?php

namespace App\Http\Requests\Admin;

use App\Models\AccommodationRatePolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pestaña Políticas del PMS: generales (`accommodation_policies`), reglas de la
 * casa (`accommodation_policy_translations`, ES) y reglas de cancelación por
 * tipo de tarifa (`accommodation_rate_policies`).
 */
class UpdateAccommodationPoliciesRequest extends FormRequest
{
    public const RATE_TYPES = AccommodationRatePolicy::TYPES;

    private const NULLABLE_STRINGS = ['checkin_from', 'checkin_to', 'checkout_from', 'checkout_to', 'house_rules'];

    public function authorize(): bool
    {
        return true;
    }

    /** El formulario manda '' para "sin dato": se guarda null. */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::NULLABLE_STRINGS as $key) {
            if ($this->has($key) && trim((string) $this->input($key)) === '') {
                $clean[$key] = null;
            }
        }

        foreach (['min_age', 'children_max_age'] as $key) {
            if ($this->input($key) === '') {
                $clean[$key] = null;
            }
        }

        $this->merge($clean);
    }

    public function rules(): array
    {
        $rules = [
            'checkin_from'     => ['sometimes', 'nullable', 'date_format:H:i'],
            'checkin_to'       => ['sometimes', 'nullable', 'date_format:H:i'],
            'checkout_from'    => ['sometimes', 'nullable', 'date_format:H:i'],
            'checkout_to'      => ['sometimes', 'nullable', 'date_format:H:i'],
            'min_age'          => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'allow_children'   => ['sometimes', 'boolean'],
            'children_max_age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:17'],
            'allow_pets'       => ['sometimes', 'boolean'],
            'allow_smoking'    => ['sometimes', 'boolean'],
            'allow_parties'    => ['sometimes', 'boolean'],
            'payment_card'     => ['sometimes', 'boolean'],
            'payment_cash'     => ['sometimes', 'boolean'],
            'payment_transfer' => ['sometimes', 'boolean'],
            'payment_crypto'   => ['sometimes', 'boolean'],
            'house_rules'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'rate_policies'    => ['sometimes', 'array:'.implode(',', self::RATE_TYPES)],
        ];

        foreach (self::RATE_TYPES as $type) {
            $rules["rate_policies.{$type}"] = ['sometimes', 'nullable', 'array'];
            $rules["rate_policies.{$type}.cancel_days"] = ['nullable', 'integer', 'min:0', 'max:365'];
            $rules["rate_policies.{$type}.cancel_penalty"] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $rules["rate_policies.{$type}.no_show_penalty"] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'checkin_from' => 'check-in desde',
            'checkin_to' => 'check-in hasta',
            'checkout_from' => 'check-out desde',
            'checkout_to' => 'check-out hasta',
            'min_age' => 'edad mínima',
            'children_max_age' => 'edad límite para niños',
            'rate_policies.*.cancel_days' => 'días de cancelación gratuita',
            'rate_policies.*.cancel_penalty' => 'penalidad por cancelación tardía',
            'rate_policies.*.no_show_penalty' => 'cargo por no-show',
        ];
    }
}
