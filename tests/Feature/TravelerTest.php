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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Parte de viajeros: pasajeros por estadía, registro y directorio de huéspedes. */
class TravelerTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private int $room;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $account = Account::create(['name' => 'Cuenta viajeros test']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id, 'city_id' => $cityId,
            'name' => 'Hotel viajeros test', 'slug' => 'hotel-viajeros-test-'.uniqid(),
        ]);
        Sanctum::actingAs(User::create([
            'name' => 'user '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT, 'account_id' => $account->id,
        ]));
        $this->type = DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id, 'standard_occupancy' => 2, 'max_occupancy' => 4,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->room = DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id, 'room_type_id' => $this->type, 'number' => '101',
            'housekeeping_status' => 'vc', 'created' => now(), 'modified' => now(),
        ]);
    }

    private function reservation(int $from, int $to, ?int $roomId = null, array $extra = []): string
    {
        $guestId = DB::table('guests')->insertGetId(['first_name' => 'Ana', 'last_name' => 'Titular '.uniqid()]);
        $id = (string) Str::uuid();
        $checkin = Reservation::today()->addDays($from)->toDateString();
        $checkout = Reservation::today()->addDays($to)->toDateString();
        DB::table('reservations')->insert(array_merge([
            'id' => $id, 'accommodation_id' => $this->acc->id, 'guest_id' => $guestId,
            'room_id' => $roomId ?? $this->room, 'room_type_id' => $this->type, 'currency_id' => 'ARS', 'language_id' => 'es',
            'package_id' => 0, 'status_id' => 2, 'payment_method_id' => 0, 'arrival' => $checkin, 'departure' => $checkout,
            'checkin_date' => $checkin, 'checkout_date' => $checkout, 'reservation_type' => 1, 'created' => now(), 'modified' => now(),
        ], $extra));
        DB::table('reservation_guests')->insert(['reservation_id' => $id, 'guest_id' => $guestId, 'is_primary' => true]);

        return $id;
    }

    private function checkIn(string $id, array $companions, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/reservations/{$id}/check-in", array_replace_recursive([
            'adults' => 2,
            'children' => 1,
            'guest' => [
                'first_name' => 'Ana', 'last_name' => 'Pérez', 'document_type' => 'passport',
                'document_number' => 'xa123456', 'document_country' => 'cl', 'nationality' => 'CL',
                'birthdate' => '1985-04-02',
            ],
            'companions' => $companions,
            'vehicle' => ['plate' => 'ab 123 cd', 'description' => 'Fiat Cronos gris'],
        ], $extra));
    }

    private function adult(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Juan', 'last_name' => 'Pérez', 'document_type' => 'dni',
            'document_number' => '28111222', 'nationality' => 'AR',
        ], $overrides);
    }

    #[Test]
    public function el_check_in_registra_acompanantes_menores_y_vehiculo(): void
    {
        $id = $this->reservation(0, 2);
        $birth = Reservation::today()->subYears(7)->toDateString();

        $this->checkIn($id, [
            $this->adult(['document_number' => '']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['companions.0.document_number']);

        $this->checkIn($id, [
            $this->adult(),
            ['first_name' => 'Sofía', 'last_name' => 'Pérez', 'nationality' => 'AR', 'birthdate' => $birth],
        ])
            ->assertOk()
            ->assertJsonPath('vehicle_plate', 'AB123CD')
            ->assertJsonPath('guest_document_country', 'CL')
            ->assertJsonCount(3, 'travelers')
            ->assertJsonPath('travelers.0.primary', true)
            ->assertJsonPath('travelers.0.document_number', 'XA123456')
            ->assertJsonPath('travelers.1.minor', false)
            ->assertJsonPath('travelers.2.minor', true)
            ->assertJsonPath('travelers.2.age', 7);
    }

    #[Test]
    public function reusa_el_huesped_por_documento_y_la_edicion_reemplaza_acompanantes(): void
    {
        $first = $this->reservation(-5, -2);
        $this->putJson("/api/v1/reservations/{$first}/travelers", ['companions' => [$this->adult()]])->assertOk();
        $juan = DB::table('guests')->where('document_number', '28111222')->value('id');

        // Lookup por documento exacto.
        $this->getJson('/api/v1/guests/lookup?document_type=dni&document_number=28111222')
            ->assertOk()->assertJsonPath('data.0.id', $juan);

        $second = $this->reservation(0, 2);
        $this->putJson("/api/v1/reservations/{$second}/travelers", [
            'companions' => [$this->adult(['first_name' => 'Juan Carlos'])],
        ])->assertOk()->assertJsonPath('travelers.1.id', $juan)->assertJsonPath('travelers.1.first_name', 'Juan Carlos');

        // Sin `companions` no se tocan; con lista vacía se quitan.
        $this->putJson("/api/v1/reservations/{$second}/travelers", ['vehicle' => ['plate' => 'AA000AA']])
            ->assertOk()->assertJsonCount(2, 'travelers');
        $this->putJson("/api/v1/reservations/{$second}/travelers", ['companions' => []])
            ->assertOk()->assertJsonCount(1, 'travelers');

        // El titular no puede repetirse como acompañante.
        $titular = $this->getJson("/api/v1/reservations/{$second}")->json('travelers.0');
        DB::table('guests')->where('id', $titular['id'])->update(['document_type' => 'dni', 'document_number' => '30999888']);
        $this->putJson("/api/v1/reservations/{$second}/travelers", [
            'companions' => [$this->adult(['document_number' => '30999888'])],
        ])->assertUnprocessable()->assertJsonValidationErrors(['companions.0.document_number']);
    }

    #[Test]
    public function el_registro_lista_una_fila_por_pasajero_con_filtros(): void
    {
        $inHouse = $this->reservation(-1, 2, null, ['checked_in_at' => now(), 'status_id' => 4]);
        $this->putJson("/api/v1/reservations/{$inHouse}/travelers", [
            'companions' => [$this->adult(['document_type' => 'passport', 'document_number' => 'P1', 'document_country' => 'BR', 'nationality' => 'BR'])],
            'vehicle' => ['plate' => 'AC 111 BB'],
        ])->assertOk();

        $room2 = DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id, 'room_type_id' => $this->type, 'number' => '102',
            'housekeeping_status' => 'vc', 'created' => now(), 'modified' => now(),
        ]);
        $this->reservation(-10, -8, $room2);

        $base = '/api/v1/travelers?accommodation_id='.$this->acc->id;

        $this->getJson($base)->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.room', '101')
            ->assertJsonPath('data.0.primary', true)
            ->assertJsonPath('data.1.document_country', 'BR')
            ->assertJsonPath('data.1.vehicle_plate', 'AC111BB');

        $this->getJson($base.'&in_house=1')->assertJsonCount(2, 'data');
        $this->getJson($base.'&nationality=foreign')->assertJsonCount(1, 'data');
        $this->getJson($base.'&q=juan')->assertJsonCount(1, 'data');
    }

    #[Test]
    public function el_directorio_muestra_estadias_y_no_huespedes_ajenos(): void
    {
        $a = $this->reservation(-20, -18, null, ['total_amount' => 9000000, 'vehicle_plate' => 'AA111AA']);
        $this->putJson("/api/v1/reservations/{$a}/travelers", ['companions' => [$this->adult()]])->assertOk();
        $b = $this->reservation(-5, -3);
        $this->putJson("/api/v1/reservations/{$b}/travelers", ['companions' => [$this->adult()]])->assertOk();
        $juan = DB::table('guests')->where('document_number', '28111222')->value('id');

        $this->getJson('/api/v1/guests?accommodation_id='.$this->acc->id.'&q=28111222')
            ->assertOk()->assertJsonPath('data.0.id', $juan)->assertJsonPath('data.0.stays', 2);

        $this->getJson("/api/v1/guests/{$juan}?accommodation_id={$this->acc->id}")
            ->assertOk()
            ->assertJsonCount(2, 'history')
            ->assertJsonPath('history.0.primary', false)
            ->assertJsonPath('nights', 4)
            ->assertJsonPath('vehicles', ['AA111AA']);

        $other = Accommodation::create([
            'account_id' => Account::create(['name' => 'Otra'])->id, 'city_id' => $this->acc->city_id,
            'name' => 'Ajeno', 'slug' => 'ajeno-'.uniqid(),
        ]);
        $this->getJson("/api/v1/guests/{$juan}?accommodation_id={$other->id}")->assertNotFound();
        $this->getJson('/api/v1/travelers?accommodation_id='.$other->id)->assertNotFound();
    }
}
