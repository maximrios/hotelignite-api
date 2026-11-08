<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra pedido en una reserva, con la foto del nombre y el precio de ese momento. En una
 * solicitud de varias habitaciones cuelga de la primera del grupo. El hotel puede quitarlo.
 */
class ReservationExtra extends Model
{
    use BelongsToAccommodation;

    protected $fillable = [
        'reservation_id',
        'extra_id',
        'template',
        'name',
        'charge_mode',
        'unit_price',
        'quantity',
        'units',
        'total_amount',
        'requires_confirmation',
    ];

    protected $casts = [
        'unit_price' => 'integer',
        'quantity' => 'integer',
        'units' => 'integer',
        'total_amount' => 'integer',
        'requires_confirmation' => 'boolean',
    ];

    protected static function accommodationParent(): ?string
    {
        return 'reservation';
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
