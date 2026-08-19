<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Servicio del catálogo global (wifi, pileta, etc.). Alimenta los pivotes
 * `accommodation_services` y `room_type_services`.
 *
 * La tabla viene del dump legacy y tiene exactamente estas columnas:
 * `id`, `name`, `ico`, `enabled`, `type`, `is_highlighted`. **No hay `slug`, no
 * hay `icon`, no hay timestamps** — de ahí el `$timestamps = false` y el
 * accessor que traduce `icon` (nombre de la API) a `ico` (nombre de la columna).
 */
class Service extends Model
{
    public $timestamps = false;

    /**
     * `icon` es el alias público de la columna `ico`; el mutator de abajo hace
     * la traducción, así que el fillable lista el alias y no la columna.
     */
    protected $fillable = [
        'name',
        'icon',
        'enabled',
        'type',
        'is_highlighted',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_highlighted' => 'boolean',
    ];

    /**
     * `ico` no se puede renombrar sin tocar el dump legacy, así que la API
     * expone `icon` y acá se hace el mapeo.
     *
     * La columna es `NOT NULL DEFAULT '0'`: al leer, tanto `''` como el `'0'`
     * heredado se reportan como `null`; al escribir, un `null` del cliente se
     * guarda como `''` para no violar el NOT NULL.
     */
    protected function icon(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ico ?: null,
            set: fn (?string $value) => ['ico' => $value ?? ''],
        );
    }

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_services', 'service_id', 'accommodation_id');
    }
}
