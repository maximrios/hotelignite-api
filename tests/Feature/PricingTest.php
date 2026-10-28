<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Tarifas del PMS: plan base por tipo y día, derivados, ocupación y cotización. */
class PricingTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private int $dbl;

    private int $tpl;

    private int $room;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $account = Account::create(['name' => 'Cuenta tarifas test']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id, 'city_id' => $cityId,
            'name' => 'Hotel tarifas test', 'slug' => 'hotel-tarifas-test-'.uniqid(),
            // Los planes de tarifa son del Básico en adelante.
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
        ]);
        Sanctum::actingAs(User::create([
            'name' => 'user '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT, 'account_id' => $account->id,
        ]));

        $type = fn (string $code, int $std, int $max) => DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id, 'code' => $code, 'standard_occupancy' => $std,
            'max_occupancy' => $max, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->dbl = $type('DBL', 2, 3);
        $this->tpl = $type('TPL', 3, 3);
        $this->room = DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id, 'room_type_id' => $this->dbl, 'number' => '101',
            'housekeeping_status' => 'vc', 'created' => now(), 'modified' => now(),
        ]);
    }

    private function date(int $days): string
    {
        return Reservation::today()->addDays($days)->toDateString();
    }

    private function bulk(array $overrides): TestResponse
    {
        return $this->postJson('/api/v1/pricing/bulk', array_replace([
            'accommodation_id' => $this->acc->id,
            'room_type_ids' => [$this->dbl],
            'from' => $this->date(1),
            'to' => $this->date(7),
            'weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'price_mode' => 'set',
            'price_value' => 50000,
        ], $overrides));
    }

    private function grid(): TestResponse
    {
        return $this->getJson("/api/v1/pricing/grid?accommodation_id={$this->acc->id}&from={$this->date(0)}&to={$this->date(14)}");
    }

    #[Test]
    public function la_grilla_crea_la_tarifa_base_y_la_edicion_masiva_carga_precios_y_restricciones(): void
    {
        $this->grid()->assertOk()
            ->assertJsonPath('plans.0.is_base', true)
            ->assertJsonPath('plans.0.code', 'BAR')
            ->assertJsonCount(2, 'room_types');

        $this->bulk([])->assertOk()->assertJsonPath('updated', 7);

        // Fin de semana +20% y estadía mínima 2; sobre días sin precio se saltea.
        $weekend = [5, 6];
        $saturdays = collect(range(1, 7))->filter(fn ($d) => in_array(Reservation::today()->addDays($d)->dayOfWeek, $weekend, true))->count();
        $this->bulk(['weekdays' => $weekend, 'price_mode' => 'percent', 'price_value' => 20, 'min_stay' => 2, 'to' => $this->date(10)])
            ->assertOk()->assertJsonPath('updated', $saturdays);

        $this->bulk(['from' => $this->date(3), 'to' => $this->date(3), 'price_mode' => 'none', 'stop_sell' => true])->assertOk();

        $cells = $this->grid()->json('cells.'.$this->dbl);
        $weekdayDate = collect(range(1, 7))->map(fn ($d) => $this->date($d))
            ->first(fn ($d) => ! in_array(Carbon::parse($d)->dayOfWeek, $weekend, true) && $d !== $this->date(3));
        $this->assertSame(50000, $cells[$weekdayDate]['price']);
        $this->assertSame(1, $cells[$weekdayDate]['min_stay']);
        $this->assertTrue($cells[$this->date(3)]['stop_sell']);
        $weekendDate = collect(range(1, 7))->map(fn ($d) => $this->date($d))->first(fn ($d) => in_array(Carbon::parse($d)->dayOfWeek, $weekend, true));
        $this->assertSame(60000, $cells[$weekendDate]['price']);
        $this->assertSame(2, $cells[$weekendDate]['min_stay']);

        $this->bulk(['price_mode' => 'none'])->assertUnprocessable()->assertJsonValidationErrors(['price_mode']);
    }

    #[Test]
    public function un_plan_derivado_y_la_ocupacion_entran_en_la_cotizacion(): void
    {
        $this->grid();
        $this->bulk(['weekdays' => [0, 1, 2, 3, 4, 5, 6]])->assertOk();

        $this->putJson("/api/v1/pricing/room-types/{$this->dbl}", [
            'extra_adult_price' => 10000, 'extra_child_price' => 5000, 'single_use_discount' => 8000,
        ])->assertOk();

        $plans = $this->postJson('/api/v1/pricing/plans', [
            'accommodation_id' => $this->acc->id, 'name' => 'No reembolsable', 'code' => 'nr',
            'cancellation' => 'non_refundable', 'meal_plan' => 'ro',
            'adjustment_type' => 'percent', 'adjustment_value' => -10, 'room_type_ids' => [$this->dbl],
        ])->assertCreated()->json();
        $nr = collect($plans)->firstWhere('code', 'NR');
        $this->assertTrue($nr['derived']);

        $url = "/api/v1/reservations/quote?room_id={$this->room}&checkin_date={$this->date(1)}&checkout_date={$this->date(3)}";

        // 2 adultos: base 50.000 × 2.
        $this->getJson($url.'&adults=2')->assertOk()
            ->assertJsonPath('rate_plan.name', 'Tarifa base')
            ->assertJsonPath('total', 100000)
            ->assertJsonPath('plans.1.total', 90000);

        // 2 adultos + 1 menor (no entra en la estándar): +5.000 por noche.
        $this->getJson($url.'&adults=2&children=1&rate_plan_id='.$nr['id'])->assertOk()
            ->assertJsonPath('rate_plan.name', 'No reembolsable')
            ->assertJsonPath('total', 100000)
            ->assertJsonPath('nightly.0.price', 50000);

        // Uso single: −8.000 por noche.
        $this->getJson($url.'&adults=1')->assertOk()->assertJsonPath('total', 84000);
    }

    #[Test]
    public function el_alta_guarda_el_precio_por_noche_o_marca_tarifa_manual(): void
    {
        $this->grid();
        $this->bulk([])->assertOk();
        $base = $this->grid()->json('plans.0.id');

        $payload = fn (float $rate) => [
            'accommodation_id' => $this->acc->id, 'room_id' => $this->room,
            'checkin_date' => $this->date(1), 'checkout_date' => $this->date(3),
            'adults' => 2, 'children' => 0, 'rate' => $rate, 'rate_plan_id' => $base,
            'guest' => ['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => uniqid().'@test.com', 'phone' => null],
        ];

        $quoted = $this->postJson('/api/v1/reservations', $payload(50000))->assertCreated()->json('id');
        $row = DB::table('reservations')->where('id', $quoted)->first();
        $this->assertFalse((bool) $row->rate_is_manual);
        $this->assertSame([['date' => $this->date(1), 'price' => 5000000], ['date' => $this->date(2), 'price' => 5000000]], json_decode($row->nightly_rates, true));

        DB::table('reservations')->where('id', $quoted)->delete();
        $manual = $this->postJson('/api/v1/reservations', $payload(42000))->assertCreated()->json('id');
        $this->assertTrue((bool) DB::table('reservations')->where('id', $manual)->value('rate_is_manual'));
    }

    #[Test]
    public function el_plan_valida_codigo_y_tipos_y_el_base_no_se_borra(): void
    {
        $base = $this->grid()->json('plans.0.id');

        $this->postJson('/api/v1/pricing/plans', [
            'accommodation_id' => $this->acc->id, 'name' => 'X', 'code' => 'BAR',
            'cancellation' => 'flexible', 'meal_plan' => 'bb', 'room_type_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'adjustment_type', 'room_type_ids']);

        $this->deleteJson("/api/v1/pricing/plans/{$base}")->assertUnprocessable();

        $other = Accommodation::create([
            'account_id' => Account::create(['name' => 'Otra'])->id, 'city_id' => $this->acc->city_id,
            'name' => 'Ajeno', 'slug' => 'ajeno-'.uniqid(),
        ]);
        $this->getJson("/api/v1/pricing/grid?accommodation_id={$other->id}&from={$this->date(0)}&to={$this->date(7)}")->assertNotFound();
    }
}
