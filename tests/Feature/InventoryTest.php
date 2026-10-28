<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Inventario del PMS: tipos de habitación y habitaciones físicas. */
class InventoryTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->account = Account::create(['name' => 'Cuenta inventario test']);
        $this->acc = Accommodation::create([
            'account_id' => $this->account->id,
            'city_id' => $cityId,
            'name' => 'Hotel inventario test',
            'slug' => 'hotel-inventario-test-'.uniqid(),
        ]);

        Sanctum::actingAs(User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ]));
    }

    private function typePayload(array $overrides = []): array
    {
        return array_replace([
            'accommodation_id' => $this->acc->id,
            'name' => 'Doble estándar',
            'code' => ' dbl ',
            'standard_occupancy' => 2,
            'max_occupancy' => 3,
            'max_adults' => 2,
            'max_children' => 1,
            'size' => 18,
            'beds' => [['type' => 'double', 'quantity' => 1]],
        ], $overrides);
    }

    private function createType(array $overrides = []): int
    {
        return $this->postJson('/api/v1/inventory/room-types', $this->typePayload($overrides))->assertCreated()->json('id');
    }

    /** @return list<int> */
    private function createRooms(int $typeId, array $numbers): array
    {
        return array_column(
            $this->postJson('/api/v1/inventory/rooms', ['room_type_id' => $typeId, 'numbers' => $numbers, 'floor' => 1])
                ->assertCreated()->json(),
            'id'
        );
    }

    private function reservation(int $roomId, int $typeId, int $from, int $to): string
    {
        $id = (string) Str::uuid();
        $checkin = Reservation::today()->addDays($from)->toDateString();
        $checkout = Reservation::today()->addDays($to)->toDateString();
        DB::table('reservations')->insert([
            'id' => $id, 'accommodation_id' => $this->acc->id,
            'guest_id' => DB::table('guests')->value('id') ?? DB::table('guests')->insertGetId(['first_name' => 'T', 'last_name' => 'G']),
            'room_id' => $roomId, 'room_type_id' => $typeId, 'currency_id' => 'ARS', 'language_id' => 'es',
            'package_id' => 0, 'status_id' => 2, 'payment_method_id' => 0, 'arrival' => $checkin, 'departure' => $checkout,
            'checkin_date' => $checkin, 'checkout_date' => $checkout, 'reservation_type' => 1, 'created' => now(), 'modified' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function crea_un_tipo_con_nombre_codigo_y_camas(): void
    {
        $this->postJson('/api/v1/inventory/room-types', $this->typePayload(['beds' => [], 'max_adults' => 4, 'code' => 'd b']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['beds', 'max_adults', 'code']);

        $id = $this->createType();

        $this->getJson('/api/v1/inventory?accommodation_id='.$this->acc->id)
            ->assertOk()
            ->assertJsonPath('room_types.0.id', $id)
            ->assertJsonPath('room_types.0.name', 'Doble estándar')
            ->assertJsonPath('room_types.0.code', 'DBL')
            ->assertJsonPath('room_types.0.beds.0.type', 'double')
            ->assertJsonPath('room_types.0.rooms_count', 0);

        // Código único por alojamiento.
        $this->postJson('/api/v1/inventory/room-types', $this->typePayload(['name' => 'Otra']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->putJson("/api/v1/inventory/room-types/{$id}", [...$this->typePayload(), 'accommodation_id' => null, 'name' => 'Doble superior', 'beds' => [['type' => 'twin', 'quantity' => 2]]])
            ->assertUnprocessable();
        $payload = $this->typePayload(['name' => 'Doble superior', 'beds' => [['type' => 'twin', 'quantity' => 2]]]);
        unset($payload['accommodation_id']);
        $this->putJson("/api/v1/inventory/room-types/{$id}", $payload)
            ->assertOk()->assertJsonPath('name', 'Doble superior')->assertJsonPath('beds.0.quantity', 2);
    }

    #[Test]
    public function alta_masiva_rechaza_numeros_repetidos_y_deriva_la_cantidad(): void
    {
        $type = $this->createType();
        $this->createRooms($type, ['101', '102', '103']);

        $this->postJson('/api/v1/inventory/rooms', ['room_type_id' => $type, 'numbers' => ['103', '104']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.numbers.0', 'Ya existe la habitación 103.');

        $this->assertSame(3, (int) DB::table('room_types')->where('id', $type)->value('quantity'));
        $this->getJson('/api/v1/inventory?accommodation_id='.$this->acc->id)
            ->assertJsonPath('room_types.0.rooms_count', 3)
            ->assertJsonCount(3, 'rooms');
    }

    #[Test]
    public function no_se_borra_una_habitacion_con_reservas_futuras_y_con_historial_se_da_de_baja(): void
    {
        $type = $this->createType();
        [$r101, $r102, $r103] = $this->createRooms($type, ['101', '102', '103']);

        $this->reservation($r101, $type, 2, 4);
        $this->reservation($r102, $type, -6, -3);

        $this->deleteJson("/api/v1/inventory/rooms/{$r101}")
            ->assertStatus(409)->assertJsonPath('message', fn (string $m) => str_contains($m, 'reasignalas'));

        $this->deleteJson("/api/v1/inventory/rooms/{$r102}")
            ->assertOk()->assertJsonPath('deleted', false)->assertJsonPath('room.archived', true);
        $this->deleteJson("/api/v1/inventory/rooms/{$r103}")
            ->assertOk()->assertJsonPath('deleted', true);

        // La dada de baja sale del rack y del cupo, y su número se puede reusar.
        $this->getJson('/api/v1/rooms?accommodation_id='.$this->acc->id.'&limit=50')
            ->assertJsonMissing(['id' => $r102]);
        $this->assertSame(1, (int) DB::table('room_types')->where('id', $type)->value('quantity'));
        $this->createRooms($type, ['102']);

        $this->postJson("/api/v1/inventory/rooms/{$r102}/restore")->assertStatus(409);
    }

    #[Test]
    public function cambiar_de_tipo_con_reservas_pide_confirmacion(): void
    {
        $dbl = $this->createType();
        $tpl = $this->createType(['name' => 'Triple', 'code' => 'TPL', 'max_occupancy' => 3, 'max_adults' => 3]);
        [$r101] = $this->createRooms($dbl, ['101']);
        $reservation = $this->reservation($r101, $dbl, 1, 3);

        $this->patchJson("/api/v1/inventory/rooms/{$r101}", ['room_type_id' => $tpl])
            ->assertUnprocessable()->assertJsonValidationErrors(['room_type_id']);

        $this->patchJson("/api/v1/inventory/rooms/{$r101}", ['room_type_id' => $tpl, 'confirm_type_change' => true])
            ->assertOk()->assertJsonPath('room_type_id', $tpl)->assertJsonPath('upcoming_reservations', 1);

        // Lo vendido no cambia.
        $this->assertSame($dbl, (int) DB::table('reservations')->where('id', $reservation)->value('room_type_id'));
        $this->assertSame(0, (int) DB::table('room_types')->where('id', $dbl)->value('quantity'));

        $this->deleteJson("/api/v1/inventory/room-types/{$tpl}")->assertStatus(409);
    }

    #[Test]
    public function fuera_de_servicio_no_con_huesped_alojado(): void
    {
        $type = $this->createType();
        [$r101, $r102] = $this->createRooms($type, ['101', '102']);
        DB::table('reservations')->where('id', $this->reservation($r101, $type, -1, 2))->update(['checked_in_at' => now()]);

        $this->patchJson("/api/v1/inventory/rooms/{$r101}", ['out_of_service' => true])->assertStatus(409);

        $this->patchJson("/api/v1/inventory/rooms/{$r102}", ['out_of_service' => true, 'maintenance_note' => 'Pérdida en el baño', 'features' => ['vista']])
            ->assertOk()
            ->assertJsonPath('out_of_service', true)
            ->assertJsonPath('maintenance_note', 'Pérdida en el baño')
            ->assertJsonPath('features', ['vista']);

        $this->patchJson("/api/v1/inventory/rooms/{$r102}", ['out_of_service' => false])
            ->assertOk()->assertJsonPath('out_of_service', false)->assertJsonPath('housekeeping_status', 'vd');
    }

    #[Test]
    public function la_capacidad_del_tipo_limita_adultos_y_ninos_de_la_reserva(): void
    {
        $type = $this->createType(['max_occupancy' => 3, 'max_adults' => 2, 'max_children' => 1]);
        [$r101] = $this->createRooms($type, ['101']);
        $id = $this->reservation($r101, $type, 1, 3);

        $this->patchJson("/api/v1/reservations/{$id}", ['adults' => 3])->assertJsonValidationErrors(['adults']);
        $this->patchJson("/api/v1/reservations/{$id}", ['adults' => 2, 'children' => 2])->assertJsonValidationErrors(['children']);
        $this->patchJson("/api/v1/reservations/{$id}", ['adults' => 2, 'children' => 1])->assertOk();
    }

    #[Test]
    public function no_toca_inventario_ajeno(): void
    {
        $other = Accommodation::create([
            'account_id' => Account::create(['name' => 'Otra cuenta'])->id,
            'city_id' => $this->acc->city_id,
            'name' => 'Ajeno',
            'slug' => 'ajeno-'.uniqid(),
        ]);

        $this->getJson('/api/v1/inventory?accommodation_id='.$other->id)->assertNotFound();
        $this->postJson('/api/v1/inventory/room-types', $this->typePayload(['accommodation_id' => $other->id]))->assertNotFound();
    }
}
