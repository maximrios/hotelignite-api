<?php

namespace Tests\Feature;

use App\Agents\AgentReadiness;
use App\AI\Services\CheckAvailabilityService;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Room;
use App\Models\RoomAvailability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El agente decide el cupo como el motor de reservas (`RoomInventory`): las
 * habitaciones físicas libres, con `room_availability` sólo como grilla de
 * cierres. Antes exigía una fila por noche en `room_availability` y, como nadie
 * la carga, a toda pregunta de fechas contestaba "unknown".
 */
class AgentInventoryTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private RoomType $type;

    private Room $room;

    private CarbonImmutable $checkin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.mock_missing_data' => false]);

        $account = Account::create(['name' => 'Cuenta inventario']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id,
            'name' => 'Hotel inventario',
            'slug' => 'hotel-inventario-'.uniqid(),
            'enabled' => 1,
        ]);

        $typeId = DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'slug' => 'tipo-'.uniqid(),
            'status' => 'active',
            'max_occupancy' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->type = RoomType::findOrFail($typeId);

        $this->room = Room::findOrFail(DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'room_type_id' => $this->type->id,
            'number' => '101',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->checkin = CarbonImmutable::today()->addDays(10);
    }

    #[Test]
    public function con_habitaciones_y_sin_grilla_hay_lugar(): void
    {
        $this->assertSame('available', $this->availability());
    }

    #[Test]
    public function con_la_unica_habitacion_reservada_no_hay_lugar(): void
    {
        $this->reserve($this->checkin->subDay(), $this->checkin->addDay());

        $this->assertSame('unavailable', $this->availability());
    }

    #[Test]
    public function un_cierre_en_la_grilla_corta_la_venta(): void
    {
        RoomAvailability::create([
            'room_type_id' => $this->type->id,
            'date' => $this->checkin->addDay()->toDateString(),
            'available' => 1,
            'total' => 1,
            'closed' => true,
        ]);

        $this->assertSame('unavailable', $this->availability());
    }

    #[Test]
    public function el_grupo_que_no_entra_no_tiene_lugar(): void
    {
        $this->assertSame('unavailable', $this->availability(guests: 3));
    }

    #[Test]
    public function la_ficha_da_por_cargado_el_inventario_con_habitaciones(): void
    {
        $inventory = collect(AgentReadiness::for($this->acc))->firstWhere('key', 'inventory');

        $this->assertTrue($inventory['ok']);
        $this->assertSame('/habitaciones', $inventory['href']);
    }

    private function availability(int $guests = 2): string
    {
        $this->acc->load('roomTypes.descriptions');

        return app(CheckAvailabilityService::class)
            ->evaluate($this->acc, $this->checkin, $this->checkin->addDays(2), $guests, 'es')['status'];
    }

    private function reserve(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $guestId = DB::table('guests')->value('id')
            ?? DB::table('guests')->insertGetId(['first_name' => 'Test', 'last_name' => 'Guest']);

        DB::table('reservations')->insert([
            'id' => (string) Str::uuid(),
            'accommodation_id' => $this->acc->id,
            'guest_id' => $guestId,
            'room_id' => $this->room->id,
            'room_type_id' => $this->type->id,
            'currency_id' => 'ARS',
            'language_id' => 'es',
            'package_id' => 0,
            'status_id' => 2,
            'payment_method_id' => 0,
            'arrival' => $from->toDateString(),
            'departure' => $to->toDateString(),
            'checkin_date' => $from->toDateString(),
            'checkout_date' => $to->toDateString(),
            'rate_amount' => 1000000,
            'total_amount' => 2000000,
            'reservation_type' => 1,
            'created' => now(),
            'modified' => now(),
        ]);
    }
}
