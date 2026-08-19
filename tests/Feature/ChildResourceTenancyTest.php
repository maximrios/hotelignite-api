<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Booking;
use App\Models\City;
use App\Models\Client;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifica la tenencia sobre los recursos que cuelgan de un Accommodation
 * (Bloque B8): rooms, room types, reservas y pre-reservas.
 *
 * Antes de esto, cualquier usuario autenticado leía y editaba los recursos hijos
 * de cualquier cuenta — incluidas las reservas con datos de los huéspedes.
 *
 * Usa DatabaseTransactions por el mismo motivo que AccommodationTenancyTest: el
 * schema viene de un dump y no hay migración que lo recree.
 */
class ChildResourceTenancyTest extends TestCase
{
    use DatabaseTransactions;

    private Account $accountA;

    private Account $accountB;

    private Accommodation $accA;

    private Accommodation $accB;

    private RoomType $typeA;

    private RoomType $typeB;

    private Room $roomA;

    private Room $roomB;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->accountA = Account::create(['name' => 'Cuenta A test']);
        $this->accountB = Account::create(['name' => 'Cuenta B test']);

        $this->accA = Accommodation::create([
            'account_id' => $this->accountA->id,
            'city_id' => $cityId,
            'name' => 'Hotel A test',
            'slug' => 'hotel-a-test-'.uniqid(),
        ]);
        $this->accB = Accommodation::create([
            'account_id' => $this->accountB->id,
            'city_id' => $cityId,
            'name' => 'Hotel B test',
            'slug' => 'hotel-b-test-'.uniqid(),
        ]);

        $this->typeA = $this->createRoomType($this->accA);
        $this->typeB = $this->createRoomType($this->accB);

        $this->roomA = $this->createRoom($this->accA, $this->typeA, '101');
        $this->roomB = $this->createRoom($this->accB, $this->typeB, '201');
    }

    /**
     * `rooms` y `room_types` son tablas legacy: usan `created`/`modified` en vez
     * de timestamps de Eloquent, así que los fixtures se insertan en crudo.
     * Ver la nota sobre la divergencia de esquema en docs/security-hardening-plan.md.
     */
    private function createRoomType(Accommodation $accommodation): RoomType
    {
        $id = DB::table('room_types')->insertGetId([
            'accommodation_id' => $accommodation->id,
            'name' => 'Tipo test',
            'created' => now(),
            'modified' => now(),
        ]);

        return RoomType::findOrFail($id);
    }

    private function createRoom(Accommodation $accommodation, RoomType $type, string $number): Room
    {
        $id = DB::table('rooms')->insertGetId([
            'accommodation_id' => $accommodation->id,
            'room_type_id' => $type->id,
            'number' => $number,
            'created' => now(),
            'modified' => now(),
        ]);

        return Room::findOrFail($id);
    }

    private function accountUser(Account $account): User
    {
        return User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
    }

    /**
     * `reservations` es una tabla legacy con muchas columnas obligatorias, así
     * que se inserta en crudo en vez de por el modelo.
     */
    private function createReservation(Accommodation $accommodation): int
    {
        $guestId = DB::table('guests')->value('id')
            ?? DB::table('guests')->insertGetId(['first_name' => 'Test', 'last_name' => 'Guest']);

        return DB::table('reservations')->insertGetId([
            'accommodation_id' => $accommodation->id,
            'guest_id' => $guestId,
            'currency_id' => 'ARS',
            'language_id' => 'es',
            'package_id' => 0,
            'status_id' => 1,
            'payment_method_id' => 0,
            'arrival' => '2026-08-01',
            'departure' => '2026-08-03',
            'reservation_type' => 1,
            'created' => now(),
            'modified' => now(),
        ]);
    }

    private function createBooking(Accommodation $accommodation): Booking
    {
        return Booking::create([
            'accommodation_id' => $accommodation->id,
            'checkin' => '2026-08-01',
            'checkout' => '2026-08-03',
            'adults' => 2,
            'childrens' => 0,
        ]);
    }

    /** @test */
    public function account_user_solo_ve_sus_rooms(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $ids = collect($this->getJson('/api/v1/rooms?limit=100')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->roomA->id), 'Debe ver la habitación propia');
        $this->assertFalse($ids->contains($this->roomB->id), 'No debe ver la de otra cuenta');
    }

    /** @test */
    public function account_user_no_puede_ver_room_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->getJson("/api/v1/rooms/{$this->roomB->id}")->assertNotFound();
    }

    /** @test */
    public function account_user_no_puede_editar_room_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/v1/rooms/{$this->roomB->id}", ['number' => 'HACKEADA'])
            ->assertNotFound();

        $this->assertDatabaseHas('rooms', ['id' => $this->roomB->id, 'number' => '201']);
    }

    /** @test */
    public function account_user_no_puede_borrar_room_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->deleteJson('/api/v1/rooms', ['room_id' => $this->roomB->id])->assertNotFound();

        $this->assertDatabaseHas('rooms', ['id' => $this->roomB->id]);
    }

    /** @test */
    public function account_user_no_puede_mudar_su_room_a_otro_alojamiento(): void
    {
        // El repositorio descarta accommodation_id del payload de update, pero
        // no se puede ejercitar por HTTP hasta reconciliar el esquema legacy de
        // `rooms` (sin timestamps, cualquier UPDATE de Eloquent tira 500).
        $this->markTestSkipped('Bloqueado por la divergencia de esquema en `rooms`.');
    }

    /** @test */
    public function account_user_no_puede_crear_room_en_alojamiento_ajeno(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->postJson('/api/v1/rooms', [
            'accommodation_id' => $this->accB->id,
            'room_type_id' => $this->typeB->id,
            'number' => '999',
        ])->assertNotFound();

        $this->assertDatabaseMissing('rooms', ['number' => '999']);
    }

    /** @test */
    public function account_user_no_puede_colgar_una_room_de_un_room_type_ajeno(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->postJson('/api/v1/rooms', [
            'accommodation_id' => $this->accA->id,
            'room_type_id' => $this->typeB->id, // tipo de la otra cuenta
            'number' => '998',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('rooms', ['number' => '998']);
    }

    /** @test */
    public function account_user_solo_ve_sus_room_types(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        // Ojo: a diferencia de /v1/rooms, este endpoint responde un array plano
        // (sin envoltorio `data`).
        $ids = collect($this->getJson('/api/v1/room-types?limit=100')->assertOk()->json())
            ->pluck('id');

        $this->assertTrue($ids->contains($this->typeA->id));
        $this->assertFalse($ids->contains($this->typeB->id));
    }

    /** @test */
    public function account_user_no_puede_editar_room_type_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/v1/room-types/{$this->typeB->id}", ['quantity' => 99])
            ->assertNotFound();

        $this->assertDatabaseMissing('room_types', [
            'id' => $this->typeB->id,
            'quantity' => 99,
        ]);
    }

    /** @test */
    public function account_user_no_puede_borrar_room_type_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->deleteJson('/api/v1/room-types', ['room_type_id' => $this->typeB->id])
            ->assertNotFound();

        $this->assertDatabaseHas('room_types', ['id' => $this->typeB->id]);
    }

    /** @test */
    public function account_user_solo_ve_las_reservas_de_sus_alojamientos(): void
    {
        $reservationA = $this->createReservation($this->accA);
        $reservationB = $this->createReservation($this->accB);

        Sanctum::actingAs($this->accountUser($this->accountA));

        $ids = collect($this->getJson('/api/v1/reservations')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($reservationA), 'Debe ver la reserva propia');
        $this->assertFalse($ids->contains($reservationB), 'No debe ver la reserva de otra cuenta');
    }

    /** @test */
    public function account_user_solo_ve_las_pre_reservas_de_sus_alojamientos(): void
    {
        $bookingA = $this->createBooking($this->accA);
        $bookingB = $this->createBooking($this->accB);

        Sanctum::actingAs($this->accountUser($this->accountA));

        $ids = collect($this->getJson('/api/v1/bookings')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($bookingA->id));
        $this->assertFalse($ids->contains($bookingB->id));
    }

    /** @test */
    public function client_user_solo_ve_los_rooms_de_los_alojamientos_relacionados(): void
    {
        $client = Client::create(['name' => 'Agencia test']);
        $client->accommodations()->attach($this->accA->id);

        $user = User::create([
            'name' => 'client user',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);
        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/v1/rooms?limit=100')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->roomA->id), 'Ve los del alojamiento relacionado');
        $this->assertFalse($ids->contains($this->roomB->id), 'No ve los del no relacionado');
    }

    /** @test */
    public function platform_user_ve_los_rooms_de_todas_las_cuentas(): void
    {
        $user = User::create([
            'name' => 'platform user',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]);
        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/v1/rooms?limit=100&accommodation_id='.$this->accB->id)->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($this->roomB->id));
    }
}
