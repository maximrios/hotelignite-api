<?php

namespace App\Http\Requests\ClientPanel;

/**
 * Campos de curaduría que un client no fija sobre el catálogo global: destacar
 * reordena "En los alrededores" de todos los alojamientos, y la fuente / id
 * externo son de las importaciones del staff. Se descartan de las reglas, así que
 * `validated()` nunca los trae aunque vengan en el cuerpo.
 */
final class ClientContentFields
{
    public const RESERVED = ['is_featured', 'source', 'external_id'];

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function strip(array $rules): array
    {
        return array_diff_key($rules, array_flip(self::RESERVED));
    }
}
