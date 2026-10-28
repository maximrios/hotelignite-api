<?php

namespace App\Http\Requests\Concerns;

use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * Reglas del parte de viajeros, compartidas por el check-in y la edición de
 * pasajeros: datos extra del titular, acompañantes y vehículo. El documento es
 * obligatorio salvo para menores (hotelería: muchos no lo tienen a mano).
 */
trait TravelerRules
{
    /** @return array<string, mixed> */
    protected function travelerRules(): array
    {
        return [
            'guest.document_country' => ['nullable', 'required_if:guest.document_type,passport', 'string', 'size:2'],
            'guest.birthdate' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],

            'companions' => ['sometimes', 'array', 'max:19'],
            'companions.*.guest_id' => ['nullable', 'integer'],
            'companions.*.first_name' => ['required', 'string', 'max:255'],
            'companions.*.last_name' => ['required', 'string', 'max:255'],
            'companions.*.document_type' => ['nullable', 'in:dni,passport,cuit,other'],
            'companions.*.document_number' => ['nullable', 'string', 'max:30'],
            'companions.*.document_country' => ['nullable', 'string', 'size:2'],
            'companions.*.nationality' => ['required', 'string', 'size:2'],
            'companions.*.birthdate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'companions.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'companions.*.is_minor' => ['sometimes', 'boolean'],

            'vehicle' => ['sometimes', 'nullable', 'array'],
            'vehicle.plate' => ['nullable', 'string', 'max:15'],
            'vehicle.description' => ['nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareTravelers(): void
    {
        $companions = $this->input('companions');
        if (is_array($companions)) {
            $this->merge(['companions' => array_map(function ($c) {
                if (! is_array($c)) {
                    return $c;
                }
                foreach (['nationality', 'document_country'] as $field) {
                    if (isset($c[$field]) && is_string($c[$field])) {
                        $c[$field] = strtoupper(trim($c[$field])) ?: null;
                    }
                }
                if (isset($c['document_number']) && is_string($c['document_number'])) {
                    $c['document_number'] = trim($c['document_number']) ?: null;
                }

                return $c;
            }, $companions)]);
        }

        $guest = $this->input('guest');
        if (is_array($guest) && isset($guest['document_country']) && is_string($guest['document_country'])) {
            $guest['document_country'] = strtoupper(trim($guest['document_country'])) ?: null;
            $this->merge(['guest' => $guest]);
        }
    }

    protected function validateTravelers(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('companions', []) as $i => $c) {
                if (! is_array($c)) {
                    continue;
                }
                $minor = (bool) ($c['is_minor'] ?? false)
                    || (isset($c['age']) && is_numeric($c['age']) && (int) $c['age'] < 18)
                    || (! empty($c['birthdate']) && strtotime((string) $c['birthdate']) !== false
                        && Carbon::parse((string) $c['birthdate'])->diffInYears(now()) < 18);

                if (empty($c['document_number']) && ! $minor) {
                    $v->errors()->add("companions.{$i}.document_number", 'Ingresá el documento (sólo es opcional para menores).');
                }
                if (! empty($c['document_number']) && empty($c['document_type'])) {
                    $v->errors()->add("companions.{$i}.document_type", 'Elegí el tipo de documento.');
                }
                if (($c['document_type'] ?? null) === 'passport' && empty($c['document_country'])) {
                    $v->errors()->add("companions.{$i}.document_country", 'Elegí el país que emitió el pasaporte.');
                }
            }
        });
    }

    /** @return array<string, string> */
    protected function travelerMessages(): array
    {
        return [
            'guest.document_country.required_if' => 'Elegí el país que emitió el pasaporte.',
            'companions.*.first_name.required' => 'Ingresá el nombre.',
            'companions.*.last_name.required' => 'Ingresá el apellido.',
            'companions.*.nationality.required' => 'Elegí la nacionalidad.',
            'companions.max' => 'Hasta 19 acompañantes.',
        ];
    }
}
