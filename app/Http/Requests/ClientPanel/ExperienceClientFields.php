<?php

namespace App\Http\Requests\ClientPanel;

/**
 * Campos de una experiencia que el client no fija. A diferencia de
 * `ClientContentFields` (eventos y POI, catálogo global), `is_featured` queda:
 * sólo reordena el portal del propio client.
 */
final class ExperienceClientFields
{
    public const RESERVED = ['client_id', 'source', 'external_id'];

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function strip(array $rules): array
    {
        return array_diff_key($rules, array_flip(self::RESERVED));
    }
}
