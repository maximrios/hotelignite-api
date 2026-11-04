<?php

namespace Tests\Feature;

use App\Mail\BookingGuestMail;
use App\Mail\BookingRequestReceivedMail;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\BookingHold;
use App\Models\City;
use App\Models\Plan;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomAvailability;
use App\Models\RoomType;
use App\Models\User;
use App\Support\BookingDeadline;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanFeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingEngineTest extends TestCase
{
    use DatabaseTransactions;

    private const KEY = 'engine-test-key';

    private Accommodation $acc;

    private Account $account;

    private RoomType $type;

    private RatePlan $plan;

    private Room $room;

    private string $checkin;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.booking_engine_key' => self::KEY]);
        Mail::fake();

        $this->seed([PlanSeeder::class, FeatureSeeder::class, PlanFeatureSeeder::class]);

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->account = Account::create(['name' => 'Cuenta motor']);
        $this->acc = Accommodation::create([
            'account_id' => $this->account->id,
            'city_id' => $cityId,
            'name' => 'Hotel motor',
            'slug' => 'hotel-motor-'.uniqid(),
            'email' => 'hotel@test.com',
            'enabled' => 1,
        ]);
        $this->acc->forceFill([
            'booking_mode' => Accommodation::BOOKING_REQUEST,
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
        ])->save();

        $typeId = DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'slug' => 'tipo-'.uniqid(),
            'status' => 'active',
            'max_occupancy' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->type = RoomType::findOrFail($typeId);
        $this->room = $this->createRoom('101');

        $this->plan = RatePlan::create([
            'accommodation_id' => $this->acc->id,
            'is_base' => true,
            'name' => 'Estándar',
            'code' => 'BAR',
            'cancellation' => 'flexible',
            'meal_plan' => 'bb',
            'enabled' => true,
        ]);

        $this->checkin = now()->addDays(10)->toDateString();
        $this->checkout = now()->addDays(12)->toDateString();

        foreach ([10, 11] as $offset) {
            Rate::create([
                'rate_plan_id' => $this->plan->id,
                'room_type_id' => $this->type->id,
                'date' => now()->addDays($offset)->toDateString(),
                'price' => 5000000,
            ]);
        }
    }

    private function createRoom(string $number): Room
    {
        $id = DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'room_type_id' => $this->type->id,
            'number' => $number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Room::findOrFail($id);
    }

    private function url(string $path = ''): string
    {
        return "/api/booking/v1/{$this->acc->slug}{$path}";
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::KEY];
    }

    private function search(): TestResponse
    {
        return $this->getJson($this->url("/search?checkin={$this->checkin}&checkout={$this->checkout}&adults=2"), $this->headers());
    }

    private function hold(): TestResponse
    {
        return $this->postJson($this->url('/holds'), [
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => 2,
            'room_type_id' => $this->type->id,
            'rate_plan_id' => $this->plan->id,
        ], $this->headers());
    }

    private function confirm(string $token): TestResponse
    {
        return $this->postJson($this->url("/holds/{$token}/confirm"), [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@test.com',
            'phone' => '3875551234',
            'accepted_terms' => true,
        ], $this->headers());
    }

    private function hotelUser(): User
    {
        return User::create(['name' => 'dueño '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'), 'user_type' => 'account', 'account_id' => $this->account->id]);
    }

    #[Test]
    public function exige_la_credencial_de_servicio(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->getJson($this->url(), ['Authorization' => 'Bearer otra'])->assertUnauthorized();
    }

    #[Test]
    public function un_alojamiento_sin_motor_activado_no_vende(): void
    {
        $this->acc->forceFill(['booking_mode' => 'inquiry'])->save();

        $this->getJson($this->url(), $this->headers())->assertNotFound();
    }

    #[Test]
    public function el_plan_free_no_vende(): void
    {
        $this->acc->forceFill(['plan_id' => Plan::where('slug', 'free')->value('id')])->save();

        $this->getJson($this->url(), $this->headers())->assertNotFound();
    }

    #[Test]
    public function busca_con_precio_real(): void
    {
        $this->search()->assertOk()
            ->assertJsonPath('data.nights', 2)
            ->assertJsonPath('data.rooms.0.rooms_left', 1)
            ->assertJsonPath('data.rooms.0.plans.0.total', 100000);
    }

    #[Test]
    public function no_ofrece_noches_sin_tarifa_ni_cierres(): void
    {
        Rate::where('rate_plan_id', $this->plan->id)->where('date', $this->checkin)->delete();
        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');

        Rate::create(['rate_plan_id' => $this->plan->id, 'room_type_id' => $this->type->id, 'date' => $this->checkin, 'price' => 5000000, 'stop_sell' => true]);
        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');

        Rate::where('date', $this->checkin)->where('rate_plan_id', $this->plan->id)->update(['stop_sell' => false]);
        RoomAvailability::create(['room_type_id' => $this->type->id, 'date' => $this->checkin, 'available' => 1, 'total' => 1, 'closed' => true]);
        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');
    }

    #[Test]
    public function no_ofrece_si_no_entran_los_huespedes(): void
    {
        $this->getJson($this->url("/search?checkin={$this->checkin}&checkout={$this->checkout}&adults=3"), $this->headers())
            ->assertOk()->assertJsonCount(0, 'data.rooms');
    }

    #[Test]
    public function el_hold_aparta_la_ultima_habitacion(): void
    {
        $this->hold()->assertCreated();

        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');
        $this->hold()->assertStatus(409);
    }

    #[Test]
    public function un_hold_vencido_libera_la_habitacion_y_no_confirma(): void
    {
        $token = $this->hold()->json('data.token');

        BookingHold::query()->update(['expires_at' => now()->subMinute()]);

        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
        $this->getJson($this->url("/holds/{$token}"), $this->headers())->assertJsonPath('data.status', 'expired');
        $this->confirm($token)->assertStatus(410);
    }

    #[Test]
    public function el_precio_del_hold_no_cambia_si_cambia_la_grilla(): void
    {
        $token = $this->hold()->json('data.token');

        Rate::where('rate_plan_id', $this->plan->id)->update(['price' => 9900000]);

        $this->getJson($this->url("/holds/{$token}"), $this->headers())->assertJsonPath('data.total', 100000);
    }

    #[Test]
    public function el_token_no_sirve_en_otro_alojamiento(): void
    {
        $token = $this->hold()->json('data.token');

        $other = $this->acc->replicate()->fill(['slug' => 'otro-'.uniqid()]);
        $other->forceFill(['booking_mode' => Accommodation::BOOKING_REQUEST, 'plan_id' => $this->acc->plan_id])->save();

        $this->getJson("/api/booking/v1/{$other->slug}/holds/{$token}", $this->headers())->assertNotFound();
    }

    #[Test]
    public function confirmar_crea_una_reserva_pendiente_y_avisa(): void
    {
        $owner = $this->hotelUser();
        $token = $this->hold()->json('data.token');

        $response = $this->confirm($token)->assertOk()->assertJsonPath('data.status', 'submitted');

        $reservation = Reservation::where('confirmation_number', $response->json('data.confirmation_number'))->firstOrFail();

        $this->assertSame(Reservation::STATUS_PENDING, (int) $reservation->status_id);
        $this->assertSame(10000000, (int) $reservation->total_amount);
        $this->assertSame($this->room->id, (int) $reservation->room_id);
        $this->assertSame(3, (int) $reservation->channel_id);

        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::RECEIVED && $m->hasTo('ana@test.com'));
        Mail::assertQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $m) => $m->hasTo('hotel@test.com'));
        Mail::assertQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $m) => $m->hasTo($owner->email));
    }

    #[Test]
    public function repetir_el_envio_no_duplica(): void
    {
        $token = $this->hold()->json('data.token');

        $first = $this->confirm($token)->json('data.confirmation_number');
        $second = $this->confirm($token)->assertOk()->json('data.confirmation_number');

        $this->assertSame($first, $second);
        $this->assertSame(1, Reservation::where('accommodation_id', $this->acc->id)->count());
        Mail::assertQueued(BookingGuestMail::class, 1);
    }

    #[Test]
    public function el_hotel_confirma_la_solicitud(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertOk();
        $this->assertSame(Reservation::STATUS_CONFIRMED, (int) $reservation->refresh()->status_id);
        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::CONFIRMED);

        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertStatus(422);
    }

    #[Test]
    public function una_solicitud_pendiente_no_admite_recepcion(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        $this->assertSame(['edit', 'confirm_request', 'cancel'], $reservation->availableActions());

        Sanctum::actingAs($user);
        $this->travel(10)->days();

        $this->assertFalse($reservation->refresh()->arrivalPending());
        $this->postJson("/api/v1/reservations/{$reservation->id}/no-show", [])->assertStatus(422);
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-in", [])->assertStatus(422);
    }

    #[Test]
    public function rechazar_libera_la_habitacion_y_avisa_al_huesped(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel", ['reason' => 'Sin lugar'])->assertOk();

        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::DECLINED);
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
    }

    private function holdRooms(array $items, int $adults, int $children = 0, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->postJson($this->url('/holds'), [
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => $adults,
            'children' => $children,
            'items' => array_map(fn (RatePlan $plan) => ['room_type_id' => $this->type->id, 'rate_plan_id' => $plan->id], $items),
        ], $this->headers() + ['X-Engine-Client-Ip' => $ip]);
    }

    #[Test]
    public function aparta_varias_habitaciones_y_crea_un_grupo(): void
    {
        $this->createRoom('102');

        $token = $this->holdRooms([$this->plan, $this->plan], 3, 1)->assertCreated()->json('data.token');

        $this->getJson($this->url("/holds/{$token}"), $this->headers())
            ->assertJsonCount(2, 'data.rooms')
            ->assertJsonPath('data.total', 200000);

        $this->confirm($token)->assertOk()->assertJsonPath('data.status', 'submitted');

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertCount(2, $group);
        $this->assertSame(1, $group->pluck('booking_group_id')->unique()->count());
        $this->assertSame(3, (int) $group->sum('adults'));
        $this->assertSame(1, (int) $group->sum('children'));
        $this->assertNotNull($group->first()->request_expires_at);
        Mail::assertQueued(BookingRequestReceivedMail::class, 1);
    }

    #[Test]
    public function si_falta_una_habitacion_no_aparta_ninguna(): void
    {
        $this->holdRooms([$this->plan, $this->plan], 2)->assertStatus(409);

        $this->assertSame(0, BookingHold::where('accommodation_id', $this->acc->id)->count());
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
    }

    #[Test]
    public function rechaza_una_seleccion_donde_no_entran_los_huespedes(): void
    {
        $this->createRoom('102');

        $this->holdRooms([$this->plan, $this->plan], 5)->assertStatus(422);
        $this->holdRooms([$this->plan, $this->plan], 1, 1)->assertStatus(422);
    }

    #[Test]
    public function no_acepta_mas_del_maximo_de_habitaciones(): void
    {
        $this->holdRooms(array_fill(0, 5, $this->plan), 5)->assertStatus(422);
    }

    #[Test]
    public function limita_las_habitaciones_apartadas_por_visitante(): void
    {
        foreach (range(102, 112) as $number) {
            $this->createRoom((string) $number);
        }

        $this->holdRooms(array_fill(0, 4, $this->plan), 4)->assertCreated();
        $this->holdRooms(array_fill(0, 3, $this->plan), 3)->assertStatus(429);
    }

    #[Test]
    public function limita_la_parte_del_hotel_apartada_a_la_vez(): void
    {
        foreach (range(102, 108) as $number) {
            $this->createRoom((string) $number);
        }

        $this->holdRooms(array_fill(0, 4, $this->plan), 4, 0, '10.0.0.2')->assertCreated();
        $this->holdRooms([$this->plan], 1, 0, '10.0.0.3')->assertStatus(429);
    }

    #[Test]
    public function limita_las_solicitudes_pendientes_por_email(): void
    {
        $this->createRoom('102');
        $this->createRoom('103');

        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'))->assertOk();
        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'))->assertOk();
        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'))->assertStatus(429);
    }

    #[Test]
    public function exige_turnstile_cuando_esta_configurado(): void
    {
        config(['services.turnstile.secret_key' => 'secreto']);

        $this->holdRooms([$this->plan], 1)->assertStatus(422)->assertJsonValidationErrors('cf-turnstile-response');
    }

    #[Test]
    public function el_hotel_confirma_el_grupo_entero(): void
    {
        $user = $this->hotelUser();
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->first();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertOk();

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertTrue($group->every(fn (Reservation $r) => (int) $r->status_id === Reservation::STATUS_CONFIRMED));
        $this->assertTrue($group->every(fn (Reservation $r) => $r->request_expires_at === null));
    }

    #[Test]
    public function el_hotel_rechaza_el_grupo_entero(): void
    {
        $user = $this->hotelUser();
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->first();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel", ['reason' => 'Sin lugar'])->assertOk();

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertTrue($group->every(fn (Reservation $r) => $r->cancelled_at !== null));
        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::DECLINED);
    }

    #[Test]
    public function una_solicitud_vencida_cancela_el_grupo_y_libera_todo(): void
    {
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $this->search()->assertJsonCount(0, 'data.rooms');

        Reservation::where('accommodation_id', $this->acc->id)->update(['request_expires_at' => now()->subMinute()]);
        $this->artisan('booking:expire-requests')->assertSuccessful();

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertTrue($group->every(fn (Reservation $r) => $r->cancelled_at !== null));
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 2);
        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $mail) => $mail->kind === BookingGuestMail::EXPIRED);
        Mail::assertQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $mail) => $mail->expired);
    }

    #[Test]
    public function no_vence_lo_que_todavia_tiene_tiempo(): void
    {
        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'));

        $this->artisan('booking:expire-requests')->assertSuccessful();

        $this->assertNull(Reservation::where('accommodation_id', $this->acc->id)->first()->cancelled_at);
    }

    #[Test]
    public function los_mails_del_motor_se_renderizan(): void
    {
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->first();

        foreach ([BookingGuestMail::RECEIVED, BookingGuestMail::CONFIRMED, BookingGuestMail::DECLINED, BookingGuestMail::EXPIRED] as $kind) {
            $this->assertStringContainsString($reservation->confirmation_number, (new BookingGuestMail($reservation, $kind))->render());
        }

        $this->assertStringContainsString('se cancela sola', (new BookingRequestReceivedMail($reservation))->render());
        $this->assertStringContainsString('Venció', (new BookingRequestReceivedMail($reservation, expired: true))->render());
    }

    #[Test]
    public function el_reloj_corre_solo_en_horario_de_atencion(): void
    {
        $this->acc->forceFill(['booking_request_minutes' => 60, 'booking_attention_from' => '08:00:00', 'booking_attention_to' => '23:00:00']);
        $tz = config('app.destination_timezone');

        $madrugada = BookingDeadline::for($this->acc, now()->addDays(10), Carbon::parse('2030-03-05 03:00', $tz));
        $this->assertSame('2030-03-05 09:00', $madrugada->setTimezone($tz)->format('Y-m-d H:i'));

        $tarde = BookingDeadline::for($this->acc, now()->addDays(10), Carbon::parse('2030-03-05 22:30', $tz));
        $this->assertSame('2030-03-06 08:30', $tarde->setTimezone($tz)->format('Y-m-d H:i'));
    }

    #[Test]
    public function el_plazo_respeta_los_limites_y_el_dia_de_llegada(): void
    {
        $tz = config('app.destination_timezone');
        $now = Carbon::parse('2030-03-05 10:00', $tz);

        $this->acc->forceFill(['booking_request_minutes' => 5, 'booking_attention_from' => null, 'booking_attention_to' => null]);
        $this->assertSame('10:30', BookingDeadline::for($this->acc, $now->copy()->addDays(5), $now)->setTimezone($tz)->format('H:i'));

        $this->acc->forceFill(['booking_request_minutes' => 720, 'booking_attention_from' => '08:00:00', 'booking_attention_to' => '12:00:00']);
        $this->assertSame('2030-03-05 23:59', BookingDeadline::for($this->acc, $now, $now)->setTimezone($tz)->format('Y-m-d H:i'));
    }
}
