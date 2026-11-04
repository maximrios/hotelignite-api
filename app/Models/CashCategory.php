<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Categoría de movimiento de caja, por alojamiento. Las de sistema tienen `code`
 * (la lógica las busca por él), se pueden renombrar y no se borran. Una usada
 * sólo se desactiva.
 */
class CashCategory extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const GROUP_OPERATING = 'operating';

    public const GROUP_TREASURY = 'treasury';

    public const GROUP_ADJUSTMENT = 'adjustment';

    public const STAY = 'stay';

    public const DEPOSIT = 'deposit';

    public const REFUND = 'refund';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    /** "Otros": sin reserva vinculada, exigen concepto. */
    public const NEEDS_CONCEPT = ['other_in', 'other_out'];

    /**
     * Categorías con que arranca cada alojamiento (diseño de hotelería, 2026-11-03).
     *
     * @var list<array{code: string|null, name: string, direction: string, group: string}>
     */
    public const DEFAULTS = [
        ['code' => self::STAY, 'name' => 'Cobro de estadía', 'direction' => self::IN, 'group' => self::GROUP_OPERATING],
        ['code' => self::DEPOSIT, 'name' => 'Seña o depósito de reserva', 'direction' => self::IN, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Consumos y extras', 'direction' => self::IN, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Restaurante o bar', 'direction' => self::IN, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Excursiones y servicios de terceros', 'direction' => self::IN, 'group' => self::GROUP_OPERATING],
        ['code' => 'other_in', 'name' => 'Otros ingresos', 'direction' => self::IN, 'group' => self::GROUP_OPERATING],
        ['code' => 'owner_contribution', 'name' => 'Aporte del dueño', 'direction' => self::IN, 'group' => self::GROUP_TREASURY],
        ['code' => null, 'name' => 'Gastos menores', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Pago a proveedor', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Sueldos y adelantos', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => self::REFUND, 'name' => 'Devolución al huésped', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Comisión pagada', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => null, 'name' => 'Impuestos y servicios', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => 'other_out', 'name' => 'Otros egresos', 'direction' => self::OUT, 'group' => self::GROUP_OPERATING],
        ['code' => 'owner_withdrawal', 'name' => 'Retiro del dueño', 'direction' => self::OUT, 'group' => self::GROUP_TREASURY],
        ['code' => 'bank_deposit', 'name' => 'Depósito bancario', 'direction' => self::OUT, 'group' => self::GROUP_TREASURY],
        ['code' => self::ADJUSTMENT_IN, 'name' => 'Sobrante de arqueo', 'direction' => self::IN, 'group' => self::GROUP_ADJUSTMENT],
        ['code' => self::ADJUSTMENT_OUT, 'name' => 'Faltante de arqueo', 'direction' => self::OUT, 'group' => self::GROUP_ADJUSTMENT],
    ];

    protected $fillable = ['accommodation_id', 'code', 'name', 'direction', 'group', 'active', 'sort_order'];

    protected $casts = ['active' => 'boolean'];

    public function isSystem(): bool
    {
        return $this->code !== null;
    }

    /** Las de ajuste las genera el cierre; no se cargan a mano. */
    public function isManual(): bool
    {
        return $this->group !== self::GROUP_ADJUSTMENT;
    }
}
