<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Extra vendible de un alojamiento. Lo carga el hotel en el PMS (Tarifas → Extras),
 * partiendo de las plantillas o de cero. Nunca se borra de verdad: las reservas lo
 * referencian (y además guardan su propia copia del precio). Ver docs/extras.md.
 */
class Extra extends Model
{
    use BelongsToAccommodation, SoftDeletes;

    public const MODE_STAY = 'stay';

    public const MODE_NIGHT = 'night';

    public const MODE_PERSON = 'person';

    public const MODE_PERSON_NIGHT = 'person_night';

    public const MODES = [self::MODE_STAY, self::MODE_NIGHT, self::MODE_PERSON, self::MODE_PERSON_NIGHT];

    public const MAX_QUANTITY = 10;

    /** El desayuno no se ofrece si la tarifa elegida ya lo trae (meal_plan). */
    public const TEMPLATE_BREAKFAST = 'breakfast';

    /**
     * Sugeridos para no arrancar con la pantalla vacía: el hotel pone el precio y lo activa.
     *
     * @var array<string, array{name: string, description: string, charge_mode: string, max_quantity: int}>
     */
    public const TEMPLATES = [
        self::TEMPLATE_BREAKFAST => ['name' => 'Desayuno', 'description' => 'Para todos los huéspedes, todas las mañanas.', 'charge_mode' => self::MODE_PERSON_NIGHT, 'max_quantity' => 1],
        'parking' => ['name' => 'Cochera', 'description' => 'Lugar para el auto durante la estadía.', 'charge_mode' => self::MODE_NIGHT, 'max_quantity' => 2],
        'late_checkout' => ['name' => 'Late check-out', 'description' => 'Dejar la habitación más tarde el último día, sujeto a disponibilidad.', 'charge_mode' => self::MODE_STAY, 'max_quantity' => 1],
        'airport_transfer' => ['name' => 'Traslado desde el aeropuerto', 'description' => 'Te esperan a tu llegada.', 'charge_mode' => self::MODE_STAY, 'max_quantity' => 2],
        'crib' => ['name' => 'Cuna para bebé', 'description' => 'La arman antes de que llegues.', 'charge_mode' => self::MODE_STAY, 'max_quantity' => 2],
    ];

    protected $fillable = [
        'accommodation_id',
        'template',
        'name',
        'description',
        'image_url',
        'price',
        'charge_mode',
        'max_quantity',
        'price_includes_tax',
        'requires_confirmation',
        'enabled',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'integer',
        'max_quantity' => 'integer',
        'price_includes_tax' => 'boolean',
        'requires_confirmation' => 'boolean',
        'enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Accommodation, $this> */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    /** Cuántas unidades de la modalidad tiene una estadía: noches, personas o las dos. */
    public static function unitsFor(string $mode, int $nights, int $guests): int
    {
        return match ($mode) {
            self::MODE_NIGHT => $nights,
            self::MODE_PERSON => $guests,
            self::MODE_PERSON_NIGHT => $guests * $nights,
            default => 1,
        };
    }
}
