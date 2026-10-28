<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rack de reservas del PMS: ventana por fechas, movimientos (PATCH) y
 * cancelación, con las reglas por estado y la exclusión de solapamientos.
 */
class ReservationRackTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private Account $account;

    private RoomType $type;

    private Room $room101;

    private Room $room102;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->account = Account::create(['name' => 'Cuenta rack test']);
        $this->acc = Accommodation::create([
            'account_id' => $this->account->id,
            'city_id' => $cityId,
            'name' => 'Hotel rack test',
            'slug' => 'hotel-rack-test-'.uniqid(),
        ]);

        $typeId = DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'slug' => 'tipo-rack-'.uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->type = RoomType::findOrFail($typeId);

        $this->room101 = $this->createRoom('101');
        $this->room102 = $this->createRoom('102');

        Sanctum::actingAs(User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ]));
    }

    private function createRoom(string $number, string $housekeeping = 'vc'): Room
    {
        $id = DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'room_type_id' => $this->type->id,
            'number' => $number,
            'housekeeping_status' => $housekeeping,
            'created' => now(),
            'modified' => now(),
        ]);

        return Room::findOrFail($id);
    }

    /** Fechas relativas a hoy, en días. */
    private function createReservation(?Room $room, int $fromDays, int $toDays, array $extra = []): string
    {
        $guestId = DB::table('guests')->value('id')
            ?? DB::table('guests')->insertGetId(['first_name' => 'Test', 'last_name' => 'Guest']);

        $id = (string) Str::uuid();
        $checkin = $this->date($fromDays);
        $checkout = $this->date($toDays);

        DB::table('reservations')->insert(array_merge([
            'id' => $id,
            'accommodation_id' => $this->acc->id,
            'guest_id' => $guestId,
            'room_id' => $room?->id,
            'room_type_id' => $this->type->id,
            'currency_id' => 'ARS',
            'language_id' => 'es',
            'package_id' => 0,
            'status_id' => 2,
            'payment_method_id' => 0,
            'arrival' => $checkin,
            'departure' => $checkout,
            'checkin_date' => $checkin,
            'checkout_date' => $checkout,
            'rate_amount' => 1000000,
            'total_amount' => 1000000 * ($toDays - $fromDays),
            'reservation_type' => 1,
            'created' => now(),
            'modified' => now(),
        ], $extra));

        return $id;
    }

    /** Relativa a hoy en la hora del hotel, como la API. */
    private function date(int $days): string
    {
        return Reservation::today()->addDays($days)->toDateString();
    }

    #[Test]
    public function la_ventana_trae_solo_las_reservas_que_ocupan_alguna_noche(): void
    {
        $inside = $this->createReservation($this->room101, 2, 4);
        $before = $this->createReservation($this->room101, -5, 0);
        $after = $this->createReservation($this->room101, 14, 16);

        $ids = collect($this->getJson("/api/v1/reservations?accommodation_id={$this->acc->id}&from={$this->date(0)}&to={$this->date(14)}&per_page=500")->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($inside));
        $this->assertFalse($ids->contains($before), 'checkout es exclusivo: salir hoy no ocupa la noche de hoy');
        $this->assertFalse($ids->contains($after), 'to es exclusivo');
    }

    #[Test]
    public function mueve_una_reserva_por_llegar_y_recalcula_el_total(): void
    {
        $id = $this->createReservation($this->room101, 2, 4);

        $this->patchJson("/api/v1/reservations/{$id}", [
            'room_id' => $this->room102->id,
            'checkout_date' => $this->date(5),
        ])->assertOk()
            ->assertJsonPath('room_id', $this->room102->id)
            ->assertJsonPath('nights', 3)
            ->assertJsonPath('amount', 30000);
    }

    #[Test]
    public function rechaza_mover_sobre_otra_reserva(): void
    {
        $this->createReservation($this->room102, 3, 6);
        $id = $this->createReservation($this->room101, 2, 4);

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => $this->room102->id])
            ->assertStatus(409);
    }

    #[Test]
    public function permite_turnover_el_mismo_dia(): void
    {
        $this->createReservation($this->room102, 1, 3);
        $id = $this->createReservation($this->room101, 3, 5);

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => $this->room102->id])->assertOk();
    }

    #[Test]
    public function rechaza_habitacion_fuera_de_servicio(): void
    {
        $ooo = $this->createRoom('103', 'ooo');
        $id = $this->createReservation($this->room101, 2, 4);

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => $ooo->id])->assertStatus(409);
    }

    #[Test]
    public function in_house_no_mueve_la_llegada_pero_si_la_salida_y_la_habitacion(): void
    {
        $id = $this->createReservation($this->room101, -2, 2, ['checked_in_at' => now()->subDays(2), 'status_id' => 4]);

        $this->patchJson("/api/v1/reservations/{$id}", ['checkin_date' => $this->date(-1)])->assertStatus(422);
        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => null])->assertStatus(422);

        $this->patchJson("/api/v1/reservations/{$id}", [
            'room_id' => $this->room102->id,
            'checkout_date' => $this->date(3),
        ])->assertOk()->assertJsonPath('status', 'in');
    }

    #[Test]
    public function la_reserva_finalizada_es_de_solo_lectura(): void
    {
        $id = $this->createReservation($this->room101, -5, -2);

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => $this->room102->id])->assertStatus(422);
    }

    #[Test]
    public function por_llegar_se_puede_desasignar(): void
    {
        $id = $this->createReservation($this->room101, 2, 4);

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => null])
            ->assertOk()
            ->assertJsonPath('room_id', null)
            ->assertJsonPath('room_type_id', $this->type->id);
    }

    #[Test]
    public function no_mueve_reservas_de_otra_cuenta(): void
    {
        $id = $this->createReservation($this->room101, 2, 4);

        Sanctum::actingAs(User::create([
            'name' => 'otro '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => Account::create(['name' => 'Otra cuenta'])->id,
        ]));

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => $this->room102->id])->assertNotFound();
        $this->postJson("/api/v1/reservations/{$id}/cancel")->assertNotFound();
    }

    #[Test]
    public function cancelar_libera_la_habitacion(): void
    {
        $cancelled = $this->createReservation($this->room102, 2, 4);
        $id = $this->createReservation($this->room101, 2, 4);

        $this->postJson("/api/v1/reservations/{$cancelled}/cancel", ['reason' => 'Pedido del huésped'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->patchJson("/api/v1/reservations/{$id}", ['room_id' => $this->room102->id])->assertOk();
    }

    #[Test]
    public function la_base_rechaza_solapamientos_aunque_no_pasen_por_la_api(): void
    {
        $this->createReservation($this->room101, 2, 4);

        $this->expectException(QueryException::class);

        DB::transaction(fn () => $this->createReservation($this->room101, 3, 5));
    }

    // ─── Alta ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    #[Test]
    public function edita_ocupacion_notas_y_huesped_desde_el_detalle(): void
    {
        DB::table('room_types')->where('id', $this->type->id)->update(['max_occupancy' => 3]);
        $id = $this->createReservation($this->room101, 1, 3, ['adults' => 2]);

        $this->patchJson("/api/v1/reservations/{$id}", ['adults' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('adults');

        $this->patchJson("/api/v1/reservations/{$id}", [
            'adults' => 3,
            'children' => null,
            'internal_notes' => 'Llega tarde',
            'guest' => ['first_name' => 'Ana', 'last_name' => 'Paz', 'email' => ' ANA@Mail.com ', 'phone' => null],
        ])
            ->assertOk()
            ->assertJsonPath('adults', 3)
            ->assertJsonPath('children', 0)
            ->assertJsonPath('internal_notes', 'Llega tarde')
            ->assertJsonPath('guest', 'Ana Paz')
            ->assertJsonPath('guest_email', 'ana@mail.com')
            ->assertJsonPath('guest_phone', null)
            // Sin cambio de fechas, el total no se toca.
            ->assertJsonPath('amount', 20000);

        $closed = $this->createReservation($this->room102, -5, -2);
        $this->patchJson("/api/v1/reservations/{$closed}", ['internal_notes' => 'x'])->assertStatus(422);
    }

    private function newReservation(array $overrides = []): array
    {
        return array_replace_recursive([
            'accommodation_id' => $this->acc->id,
            'room_id' => $this->room101->id,
            'checkin_date' => $this->date(1),
            'checkout_date' => $this->date(3),
            'adults' => 2,
            'guest' => ['first_name' => 'Ana', 'last_name' => 'Rack', 'email' => 'Ana.Rack@Example.com'],
            'rate' => 45000,
        ], $overrides);
    }

    #[Test]
    public function crea_una_reserva_confirmada_con_total_y_huesped_nuevo(): void
    {
        $res = $this->postJson('/api/v1/reservations', $this->newReservation())
            ->assertCreated()
            ->assertJsonPath('room_id', $this->room101->id)
            ->assertJsonPath('room_type_id', $this->type->id)
            ->assertJsonPath('nights', 2)
            ->assertJsonPath('amount', 90000)
            ->assertJsonPath('guest', 'Ana Rack')
            ->assertJsonPath('guest_email', 'ana.rack@example.com')
            ->assertJsonPath('status', 'arriving')
            ->json();

        $this->assertMatchesRegularExpression('/^HI-[2-9A-Z]{6}$/', $res['code']);
        $this->assertDatabaseHas('reservations', ['id' => $res['id'], 'status_id' => 2, 'channel_id' => 1, 'arrival' => $this->date(1)]);
    }

    #[Test]
    public function el_alta_rechaza_habitacion_ocupada_por_campo(): void
    {
        $this->createReservation($this->room101, 2, 4);

        $this->postJson('/api/v1/reservations', $this->newReservation())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
    }

    #[Test]
    public function el_alta_valida_fechas_tarifa_y_capacidad(): void
    {
        DB::table('room_types')->where('id', $this->type->id)->update(['max_occupancy' => 2]);

        $this->postJson('/api/v1/reservations', $this->newReservation([
            'checkin_date' => $this->date(-2),
            'checkout_date' => $this->date(1),
            'rate' => 0,
            'adults' => 3,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['checkin_date', 'rate']);

        $this->postJson('/api/v1/reservations', $this->newReservation(['adults' => 3]))
            ->assertUnprocessable()->assertJsonValidationErrors(['adults']);

        // Walk-in de anoche y cortesía explícita: válidos.
        $this->postJson('/api/v1/reservations', $this->newReservation([
            'checkin_date' => $this->date(-1),
            'checkout_date' => $this->date(1),
            'rate' => 0,
            'complimentary' => true,
        ]))->assertCreated()->assertJsonPath('amount', 0);
    }

    #[Test]
    public function el_alta_rechaza_habitacion_fuera_de_servicio_y_ajena(): void
    {
        $ooo = $this->createRoom('104', 'ooo');

        $this->postJson('/api/v1/reservations', $this->newReservation(['room_id' => $ooo->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['room_id']);

        $otherRoom = DB::table('rooms')->where('accommodation_id', '<>', $this->acc->id)->value('id');
        $this->postJson('/api/v1/reservations', $this->newReservation(['room_id' => $otherRoom]))
            ->assertUnprocessable()->assertJsonValidationErrors(['room_id']);
    }

    #[Test]
    public function el_lookup_solo_encuentra_huespedes_propios(): void
    {
        $this->postJson('/api/v1/reservations', $this->newReservation([
            'guest' => ['phone' => '+54 (387) 555-1234'],
        ]))->assertCreated();

        $this->getJson('/api/v1/guests/lookup?email=ANA.RACK@example.com')
            ->assertOk()->assertJsonPath('data.0.name', 'Ana Rack')->assertJsonPath('data.0.stays', 1);
        $this->getJson('/api/v1/guests/lookup?phone=543875551234')
            ->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs(User::create([
            'name' => 'otro '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => Account::create(['name' => 'Otra cuenta'])->id,
        ]));

        $this->getJson('/api/v1/guests/lookup?email=ana.rack@example.com')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function reusa_un_huesped_existente_visible(): void
    {
        $first = $this->postJson('/api/v1/reservations', $this->newReservation())->assertCreated();
        $guestId = DB::table('reservations')->where('id', $first->json('id'))->value('guest_id');

        $this->postJson('/api/v1/reservations', [
            ...$this->newReservation(['room_id' => $this->room102->id]),
            'guest' => null,
            'guest_id' => $guestId,
        ])->assertCreated()->assertJsonPath('guest', 'Ana Rack');

        $this->postJson('/api/v1/reservations', [
            ...$this->newReservation(['room_id' => $this->room102->id, 'checkin_date' => $this->date(5), 'checkout_date' => $this->date(6)]),
            'guest' => null,
            'guest_id' => 999999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['guest_id']);
    }

    #[Test]
    public function la_cotizacion_sigue_leyendo_un_plan_legacy_por_tipo(): void
    {
        // Plan creado "a la vieja" (un plan por tipo, precios del propio plan).
        $plan = RatePlan::create(['room_type_id' => $this->type->id, 'name' => 'Estándar', 'code' => 'STD'.uniqid()]);
        foreach ([1 => 4000000, 2 => 5000000] as $day => $price) {
            Rate::create(['rate_plan_id' => $plan->id, 'date' => $this->date($day), 'price' => $price]);
        }

        $url = "/api/v1/reservations/quote?room_id={$this->room101->id}&checkin_date={$this->date(1)}&checkout_date=";

        $this->getJson($url.$this->date(3))->assertOk()
            ->assertJsonPath('rate_plan.name', 'Estándar')
            ->assertJsonPath('rate', 45000)
            ->assertJsonPath('total', 90000);

        // Falta la tarifa de la tercera noche: carga manual.
        $this->getJson($url.$this->date(4))->assertOk()
            ->assertJsonPath('rate', null)
            ->assertJsonPath('missing_dates', [$this->date(3)]);
    }

    // ─── Recepción ───────────────────────────────────────────────────────────────

    private function checkInPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'adults' => 2,
            'children' => 0,
            'guest' => [
                'first_name' => 'Ana',
                'last_name' => 'Pérez',
                'document_type' => 'dni',
                'document_number' => ' 30123456 ',
                'nationality' => 'ar',
                'phone' => '3875551234',
            ],
        ], $overrides);
    }

    #[Test]
    public function el_check_in_exige_habitacion_fecha_y_datos_minimos(): void
    {
        $unassigned = $this->createReservation(null, 0, 2);
        $future = $this->createReservation($this->room102, 1, 3);

        $this->getJson('/api/v1/reservations?accommodation_id='.$this->acc->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $unassigned, 'arrival_pending' => true]);

        $this->postJson("/api/v1/reservations/{$unassigned}/check-in", $this->checkInPayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['room_id']);
        $this->postJson("/api/v1/reservations/{$unassigned}/check-in", ['adults' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors(['guest.document_number', 'guest.nationality']);
        $this->postJson("/api/v1/reservations/{$future}/check-in", $this->checkInPayload())
            ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'adelantá la llegada'));

        DB::table('rooms')->where('id', $this->room101->id)->update(['housekeeping_status' => 'vd']);

        $this->postJson("/api/v1/reservations/{$unassigned}/check-in", $this->checkInPayload(['room_id' => $this->room101->id]))
            ->assertOk()
            ->assertJsonPath('status', 'in')
            ->assertJsonPath('room_id', $this->room101->id)
            ->assertJsonPath('guest_document_number', '30123456')
            ->assertJsonPath('guest_nationality', 'AR')
            ->assertJsonPath('actions', ['edit', 'check_out', 'undo_check_in']);

        // Sucia → ocupada-sucia.
        $this->assertSame('od', DB::table('rooms')->where('id', $this->room101->id)->value('housekeeping_status'));

        $this->deleteJson("/api/v1/reservations/{$unassigned}/check-in")
            ->assertOk()->assertJsonPath('status', 'arriving');
        $this->assertSame('vd', DB::table('rooms')->where('id', $this->room101->id)->value('housekeeping_status'));
    }

    #[Test]
    public function el_check_in_rechaza_habitacion_con_huesped_sin_salida_y_fuera_de_servicio(): void
    {
        // Debía salir ayer y no registró el check-out: sigue en la habitación.
        $this->createReservation($this->room101, -3, -1, ['checked_in_at' => now()->subDays(3), 'status_id' => 4]);
        $id = $this->createReservation(null, 0, 2);

        $this->postJson("/api/v1/reservations/{$id}/check-in", $this->checkInPayload(['room_id' => $this->room101->id]))
            ->assertStatus(409)->assertJsonPath('message', fn (string $m) => str_contains($m, 'sin check-out'));

        $ooo = $this->createRoom('103', 'ooo');
        $this->postJson("/api/v1/reservations/{$id}/check-in", $this->checkInPayload(['room_id' => $ooo->id]))
            ->assertStatus(409);
    }

    #[Test]
    public function la_salida_vencida_queda_pendiente_hasta_el_check_out(): void
    {
        $id = $this->createReservation($this->room101, -3, -1, ['checked_in_at' => now()->subDays(3), 'status_id' => 4]);

        // Aparece en la ventana del rack aunque su salida quede antes: sigue en la habitación.
        $this->getJson('/api/v1/reservations?accommodation_id='.$this->acc->id.'&from='.$this->date(0).'&to='.$this->date(7))
            ->assertJsonFragment(['id' => $id, 'status' => 'departing', 'departure_overdue' => true]);

        // Sin anticipada no pide decidir el cobro, ni toca fechas ni total.
        $this->postJson("/api/v1/reservations/{$id}/check-out")
            ->assertOk()
            ->assertJsonPath('status', 'out')
            ->assertJsonPath('checkout', $this->date(-1))
            ->assertJsonPath('amount', 20000);
        $this->assertSame('vd', DB::table('rooms')->where('id', $this->room101->id)->value('housekeeping_status'));
    }

    #[Test]
    public function el_check_out_anticipado_libera_noches_y_se_puede_deshacer(): void
    {
        $id = $this->createReservation($this->room101, -2, 3, ['checked_in_at' => now()->subDays(2), 'status_id' => 4]);

        $this->postJson("/api/v1/reservations/{$id}/check-out")
            ->assertUnprocessable()->assertJsonValidationErrors(['charge_remaining']);

        $this->postJson("/api/v1/reservations/{$id}/check-out", ['charge_remaining' => false])
            ->assertOk()
            ->assertJsonPath('status', 'out')
            ->assertJsonPath('checkout', $this->date(0))
            ->assertJsonPath('booked_checkout', $this->date(3))
            ->assertJsonPath('nights', 2)
            ->assertJsonPath('amount', 20000)
            ->assertJsonPath('actions', ['undo_check_out']);

        $this->deleteJson("/api/v1/reservations/{$id}/check-out")
            ->assertOk()
            ->assertJsonPath('status', 'in')
            ->assertJsonPath('checkout', $this->date(3))
            ->assertJsonPath('amount', 50000);

        // Cobrando lo que no usó, el total se mantiene.
        $this->postJson("/api/v1/reservations/{$id}/check-out", ['charge_remaining' => true])
            ->assertOk()->assertJsonPath('nights', 2)->assertJsonPath('amount', 50000);

        // Las noches liberadas se pueden volver a vender; ahí ya no se reabre.
        $this->createReservation($this->room101, 1, 2);
        $this->deleteJson("/api/v1/reservations/{$id}/check-out")->assertStatus(409);
    }

    #[Test]
    public function el_no_show_es_un_estado_propio_que_libera_la_habitacion(): void
    {
        $future = $this->createReservation($this->room102, 1, 3);
        $this->postJson("/api/v1/reservations/{$future}/no-show")->assertUnprocessable();

        $id = $this->createReservation($this->room101, -1, 2);

        $this->postJson("/api/v1/reservations/{$id}/no-show", ['note' => 'No atiende el teléfono'])
            ->assertOk()
            ->assertJsonPath('status', 'no_show')
            ->assertJsonPath('cancellation_reason', 'No atiende el teléfono')
            ->assertJsonPath('actions', ['undo_no_show']);

        $this->patchJson("/api/v1/reservations/{$id}", ['adults' => 1])->assertUnprocessable();

        // La habitación quedó libre: se puede vender esas noches.
        $this->createReservation($this->room101, 0, 2);

        // Apareció el huésped: vuelve por llegar, sin la habitación que ya se vendió.
        $this->deleteJson("/api/v1/reservations/{$id}/no-show")
            ->assertOk()
            ->assertJsonPath('status', 'arriving')
            ->assertJsonPath('room_id', null)
            ->assertJsonPath('cancellation_reason', null);
    }
}
