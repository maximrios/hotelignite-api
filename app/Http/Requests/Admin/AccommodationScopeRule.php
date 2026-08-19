<?php

namespace App\Http\Requests\Admin;

use App\Models\Accommodation;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

/**
 * Regla de negocio del acotamiento por alojamiento (pivote `accommodation_user`),
 * compartida por Store y UpdateUserRequest para que no diverjan.
 *
 * Dos invariantes:
 *  - Solo un user `account` lleva acotamiento; los otros tipos scopean por otra vía.
 *  - Cada alojamiento tiene que ser de la cuenta del user. Sin este corte se
 *    podría scopear un user a datos de otro tenant — la fuga que el pivote existe
 *    para evitar. `exists:accommodations,id` no alcanza: garantiza que existe, no
 *    que sea de *esta* cuenta.
 */
class AccommodationScopeRule
{
    /**
     * @param  array<int, int>  $accommodationIds
     */
    public static function validate(Validator $validator, array $accommodationIds, ?string $userType, ?int $accountId): void
    {
        if ($accommodationIds === []) {
            return;
        }

        if ($userType !== User::TYPE_ACCOUNT) {
            $validator->errors()->add('accommodation_ids', 'Solo un usuario de tipo hotelero puede acotarse a alojamientos.');

            return;
        }

        $belonging = Accommodation::whereIn('id', $accommodationIds)
            ->where('account_id', $accountId)
            ->count();

        if ($belonging !== count($accommodationIds)) {
            $validator->errors()->add('accommodation_ids', 'Todos los alojamientos deben pertenecer a la cuenta del usuario.');
        }
    }
}
