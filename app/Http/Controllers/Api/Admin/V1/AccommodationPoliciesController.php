<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAccommodationPoliciesRequest;
use App\Models\Accommodation;
use App\Models\AccommodationPolicy;
use App\Models\AccommodationRatePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Pestaña Políticas del PMS. Antes el PMS mandaba estos campos al PATCH de la
 * ficha, que los descartaba en silencio (no están en `PatchAccommodationRequest`
 * ni en la tabla `accommodations`): el toast decía "guardado" y no se guardaba nada.
 *
 * Lo mismo lee el agente (`GetAccommodationDetailsService`) y la ficha de
 * readiness (`AgentReadiness`).
 */
class AccommodationPoliciesController extends Controller
{
    private const LANGUAGE = 'es';

    private const GENERAL = [
        'checkin_from', 'checkin_to', 'checkout_from', 'checkout_to',
        'min_age', 'allow_children', 'children_max_age',
        'allow_pets', 'allow_smoking', 'allow_parties',
        'payment_card', 'payment_cash', 'payment_transfer', 'payment_crypto',
    ];

    private const RATE_FIELDS = ['cancel_days', 'cancel_penalty', 'no_show_penalty'];

    public function show(Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);

        return response()->json(['data' => $this->payload($accommodation)]);
    }

    public function update(UpdateAccommodationPoliciesRequest $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $data = $request->validated();

        DB::transaction(function () use ($accommodation, $data) {
            $general = Arr::only($data, self::GENERAL);

            if ($general !== [] || array_key_exists('house_rules', $data)) {
                $policy = AccommodationPolicy::query()->firstOrNew(['accommodation_id' => $accommodation->id]);
                $policy->fill($general)->save();

                if (array_key_exists('house_rules', $data)) {
                    $policy->translations()->updateOrCreate(
                        ['language_id' => self::LANGUAGE],
                        ['house_rules' => $data['house_rules']],
                    );
                }
            }

            foreach ($data['rate_policies'] ?? [] as $type => $values) {
                $values = Arr::only($values ?? [], self::RATE_FIELDS);
                $empty = collect(self::RATE_FIELDS)->every(fn (string $f): bool => ($values[$f] ?? null) === null);

                // Un tipo sin ningún dato no es una regla: si quedara la fila, la
                // ficha del agente diría que hay política de cancelación.
                if ($empty) {
                    $accommodation->ratePolicies()->where('rate_type', $type)->delete();

                    continue;
                }

                AccommodationRatePolicy::query()->updateOrCreate(
                    ['accommodation_id' => $accommodation->id, 'rate_type' => $type],
                    array_merge(array_fill_keys(self::RATE_FIELDS, null), $values),
                );
            }
        });

        return response()->json(['data' => $this->payload($accommodation)]);
    }

    private function payload(Accommodation $accommodation): array
    {
        $policy = AccommodationPolicy::query()
            ->with('translations')
            ->where('accommodation_id', $accommodation->id)
            ->first();

        $general = [];
        foreach (self::GENERAL as $field) {
            $value = $policy?->{$field};
            // La columna es `time`: viene "14:00:00" y el input del PMS es HH:MM.
            if ($value !== null && str_starts_with($field, 'check')) {
                $value = substr((string) $value, 0, 5);
            }
            $general[$field] = $value ?? (str_starts_with($field, 'allow_') || str_starts_with($field, 'payment_') ? false : null);
        }

        $rates = $accommodation->ratePolicies()->get()->keyBy('rate_type');

        return array_merge($general, [
            'configured' => $policy !== null,
            'house_rules' => $policy?->translations->firstWhere('language_id', self::LANGUAGE)?->house_rules,
            'rate_policies' => collect(UpdateAccommodationPoliciesRequest::RATE_TYPES)
                ->mapWithKeys(fn (string $type) => [$type => $rates->has($type)
                    ? Arr::only($rates[$type]->toArray(), self::RATE_FIELDS)
                    : null])
                ->all(),
        ]);
    }
}
