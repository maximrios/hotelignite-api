<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\City;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Caja del PMS: turnos, arqueo, libro inmutable, cobros de reservas, reportes y plan. */
class CashRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/cash';

    private Accommodation $acc;

    private User $user;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $account = Account::create(['name' => 'Cuenta caja test']);
        $this->acc = $this->hotel($account, 'starter');
        $this->user = $this->userFor($account);
        Sanctum::actingAs($this->user);
    }

    private function hotel(Account $account, string $plan): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $this->cityId,
            'name' => 'Hotel caja test',
            'slug' => 'hotel-caja-test-'.uniqid(),
            'plan_id' => Plan::where('slug', $plan)->value('id'),
        ]);
    }

    private function userFor(Account $account): User
    {
        return User::create([
            'name' => 'Recepción',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
    }

    private function reservation(int $totalCents, string $currency = 'ARS', int $from = 0, int $to = 2): string
    {
        $id = (string) Str::uuid();
        $checkin = Reservation::today()->addDays($from)->toDateString();
        $checkout = Reservation::today()->addDays($to)->toDateString();
        DB::table('reservations')->insert([
            'id' => $id, 'accommodation_id' => $this->acc->id,
            'guest_id' => DB::table('guests')->value('id') ?? DB::table('guests')->insertGetId(['first_name' => 'T', 'last_name' => 'G']),
            'channel_id' => 0, 'status_id' => Reservation::STATUS_CONFIRMED,
            'checkin_date' => $checkin, 'checkout_date' => $checkout,
            'arrival' => $checkin, 'departure' => $checkout, 'nights' => $to - $from,
            'adults' => 2, 'childrens' => 0, 'currency_id' => $currency, 'currency' => $currency,
            'language_id' => 'es', 'package_id' => 0, 'payment_method_id' => 0, 'reservation_type' => 1,
            'total_amount' => $totalCents, 'created' => now(), 'modified' => now(), 'user_created' => $this->user->id,
        ]);

        return $id;
    }

    private function category(string $code): int
    {
        $this->getJson(self::BASE.'?accommodation_id='.$this->acc->id)->assertOk();

        return (int) CashCategory::where('accommodation_id', $this->acc->id)->where('code', $code)->value('id');
    }

    private function openShift(int $ars = 10000, int $usd = 0): int
    {
        return $this->postJson(self::BASE.'/shifts', [
            'accommodation_id' => $this->acc->id,
            'balances' => [['currency' => 'ARS', 'amount' => $ars], ['currency' => 'USD', 'amount' => $usd]],
        ])->assertCreated()->json('id');
    }

    /** @param array<string, mixed> $extra */
    private function record(string $code, int $amount, string $method = 'cash', array $extra = [])
    {
        return $this->postJson(self::BASE.'/entries', [
            'accommodation_id' => $this->acc->id,
            'category_id' => $this->category($code),
            'amount' => $amount,
            'currency' => 'ARS',
            'method' => $method,
        ] + $extra);
    }

    #[Test]
    public function el_plan_free_no_tiene_caja(): void
    {
        $free = $this->hotel(Account::find($this->acc->account_id), 'free');

        $this->getJson(self::BASE.'?accommodation_id='.$free->id)->assertForbidden();
    }

    #[Test]
    public function otro_hotelero_no_ve_la_caja(): void
    {
        $this->openShift();
        Sanctum::actingAs($this->userFor(Account::create(['name' => 'Otra cuenta'])));

        $this->getJson(self::BASE.'?accommodation_id='.$this->acc->id)->assertNotFound();
    }

    #[Test]
    public function siembra_categorias_y_no_hay_dos_cajas_abiertas(): void
    {
        $this->getJson(self::BASE.'?accommodation_id='.$this->acc->id)
            ->assertOk()
            ->assertJsonPath('shift', null)
            ->assertJsonCount(count(CashCategory::DEFAULTS), 'categories');

        $this->openShift();
        $this->postJson(self::BASE.'/shifts', ['accommodation_id' => $this->acc->id])->assertStatus(409);
    }

    #[Test]
    public function el_efectivo_exige_caja_abierta_pero_la_transferencia_no(): void
    {
        $this->record(CashCategory::DEPOSIT, 5000)->assertStatus(409);

        $this->record(CashCategory::DEPOSIT, 5000, 'transfer', ['occurred_on' => Reservation::today()->subDay()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('shift_id', null)
            ->assertJsonPath('occurred_on', Reservation::today()->subDay()->toDateString());

        $this->getJson(self::BASE.'?accommodation_id='.$this->acc->id)->assertJsonCount(1, 'off_shift_entries');
    }

    #[Test]
    public function la_fecha_no_puede_ser_futura(): void
    {
        $this->record(CashCategory::DEPOSIT, 5000, 'transfer', ['occurred_on' => Reservation::today()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_on');
    }

    #[Test]
    public function la_misma_clave_no_duplica_el_movimiento(): void
    {
        $this->openShift();
        $first = $this->record(CashCategory::STAY, 3000, 'cash', ['idempotency_key' => 'k-1'])->assertCreated()->json('id');
        $again = $this->record(CashCategory::STAY, 3000, 'cash', ['idempotency_key' => 'k-1'])->json('id');

        $this->assertSame($first, $again);
        $this->assertSame(1, CashEntry::where('accommodation_id', $this->acc->id)->count());
    }

    #[Test]
    public function otros_exige_concepto(): void
    {
        $this->openShift();
        $this->record('other_out', 1000)->assertUnprocessable()->assertJsonValidationErrors('concept');
        $this->record('other_out', 1000, 'cash', ['concept' => 'Lamparitas'])->assertCreated();
    }

    #[Test]
    public function el_cierre_arquea_solo_el_efectivo_y_registra_la_diferencia(): void
    {
        $shift = $this->openShift(10000);
        $this->record(CashCategory::STAY, 50000)->assertCreated();          // +500 efectivo
        $this->record(CashCategory::STAY, 80000, 'debit')->assertCreated(); // no entra al arqueo
        $this->record('owner_withdrawal', 20000)->assertCreated();          // −200 efectivo

        // Esperado ARS: 100 + 500 − 200 = 400. Contado 390: faltan 10.
        $close = ['counted' => [['currency' => 'ARS', 'amount' => 39000], ['currency' => 'USD', 'amount' => 0]]];
        $this->postJson(self::BASE."/shifts/{$shift}/close", $close)
            ->assertUnprocessable()->assertJsonValidationErrors('notes');

        $res = $this->postJson(self::BASE."/shifts/{$shift}/close", $close + ['notes' => 'Vuelto mal dado'])->assertOk();
        $ars = collect($res->json('balances'))->firstWhere('currency', 'ARS');
        $this->assertSame(40000, $ars['expected']);
        $this->assertSame(39000, $ars['counted']);
        $this->assertSame(-1000, $ars['difference']);
        $res->assertJsonPath('status', 'closed');

        $adjustment = CashEntry::where('cash_shift_id', $shift)->latest('id')->first();
        $this->assertSame(CashCategory::OUT, $adjustment->direction);
        $this->assertSame(1000, $adjustment->amount);

        // El fondo sugerido del turno siguiente es lo contado.
        $this->getJson(self::BASE.'?accommodation_id='.$this->acc->id)
            ->assertJsonPath('suggested_opening.0', ['currency' => 'ARS', 'amount' => 39000]);

        $this->postJson(self::BASE."/shifts/{$shift}/close", $close + ['notes' => 'x'])->assertStatus(409);
    }

    #[Test]
    public function el_cierre_sin_diferencia_no_pide_nota(): void
    {
        $shift = $this->openShift(10000);
        $this->postJson(self::BASE."/shifts/{$shift}/close", [
            'counted' => [['currency' => 'ARS', 'amount' => 10000], ['currency' => 'USD', 'amount' => 0]],
        ])->assertOk();

        $this->assertSame(0, CashEntry::where('cash_shift_id', $shift)->count());
    }

    #[Test]
    public function anular_crea_un_asiento_inverso_una_sola_vez(): void
    {
        $this->openShift();
        $id = $this->record(CashCategory::STAY, 5000)->json('id');

        $res = $this->postJson(self::BASE."/entries/{$id}/reverse", ['reason' => 'Monto equivocado'])->assertCreated();
        $res->assertJsonPath('reversal.direction', CashCategory::OUT)
            ->assertJsonPath('reversal.reverses_entry_id', $id)
            ->assertJsonPath('original.reversed_by_entry_id', $res->json('reversal.id'));

        $this->postJson(self::BASE."/entries/{$id}/reverse", ['reason' => 'Otra vez'])->assertStatus(409);
        $this->postJson(self::BASE.'/entries/'.$res->json('reversal.id').'/reverse', ['reason' => 'x x x'])->assertStatus(422);
    }

    #[Test]
    public function el_libro_no_se_edita(): void
    {
        $this->openShift();
        $entry = CashEntry::find($this->record(CashCategory::STAY, 5000)->json('id'));

        $this->expectException(\LogicException::class);
        $entry->update(['amount' => 1]);
    }

    #[Test]
    public function anular_efectivo_de_un_turno_cerrado_exige_caja_abierta(): void
    {
        $shift = $this->openShift(0);
        $id = $this->record(CashCategory::STAY, 5000)->json('id');
        $this->postJson(self::BASE."/shifts/{$shift}/close", [
            'counted' => [['currency' => 'ARS', 'amount' => 5000], ['currency' => 'USD', 'amount' => 0]],
        ])->assertOk();

        $this->postJson(self::BASE."/entries/{$id}/reverse", ['reason' => 'Error'])->assertStatus(409);

        $new = $this->openShift(5000);
        $this->postJson(self::BASE."/entries/{$id}/reverse", ['reason' => 'Error'])
            ->assertCreated()->assertJsonPath('reversal.shift_id', $new);
    }

    #[Test]
    public function el_saldo_de_la_reserva_se_calcula_con_sus_cobros(): void
    {
        $this->openShift();
        $reservation = $this->reservation(100000);

        $this->record(CashCategory::DEPOSIT, 30000, 'transfer', ['reservation_id' => $reservation])->assertCreated();
        // Cobro en USD sobre una reserva en ARS: exige el equivalente.
        $usd = [
            'accommodation_id' => $this->acc->id,
            'category_id' => $this->category(CashCategory::STAY),
            'amount' => 5000, 'currency' => 'USD', 'method' => 'cash',
            'reservation_id' => $reservation,
        ];
        $this->postJson(self::BASE.'/entries', $usd)->assertUnprocessable()->assertJsonValidationErrors('reservation_amount');
        $usdId = $this->postJson(self::BASE.'/entries', $usd + ['reservation_amount' => 50000])->assertCreated()->json('id');

        $this->getJson(self::BASE."/reservations/{$reservation}")
            ->assertOk()
            ->assertJsonPath('total', 100000)
            ->assertJsonPath('paid', 80000)
            ->assertJsonPath('balance', 20000);

        $this->getJson(self::BASE.'/receivables?accommodation_id='.$this->acc->id)
            ->assertJsonPath('data.0.reservation.id', $reservation)
            ->assertJsonPath('data.0.balance', 20000);

        // La anulación devuelve el saldo.
        $this->postJson(self::BASE."/entries/{$usdId}/reverse", ['reason' => 'Billete falso'])->assertCreated();
        $this->getJson(self::BASE."/reservations/{$reservation}")->assertJsonPath('balance', 70000);
    }

    #[Test]
    public function no_se_vincula_una_reserva_de_otro_hotel(): void
    {
        $this->openShift();
        $other = $this->hotel(Account::find($this->acc->account_id), 'starter');
        $reservation = $this->reservation(1000);
        DB::table('reservations')->where('id', $reservation)->update(['accommodation_id' => $other->id]);

        $this->record(CashCategory::STAY, 1000, 'cash', ['reservation_id' => $reservation])
            ->assertUnprocessable()->assertJsonValidationErrors('reservation_id');
    }

    #[Test]
    public function el_reporte_separa_operativo_de_tesoreria_y_neutraliza_anulaciones(): void
    {
        $this->openShift(0);
        $this->record(CashCategory::STAY, 50000)->assertCreated();
        $this->record(CashCategory::STAY, 20000, 'credit')->assertCreated();
        $wrong = $this->record(CashCategory::STAY, 9900)->json('id');
        $this->postJson(self::BASE."/entries/{$wrong}/reverse", ['reason' => 'Duplicado'])->assertCreated();
        $this->record('owner_withdrawal', 10000)->assertCreated();

        $today = Reservation::today()->toDateString();
        $res = $this->getJson(self::BASE."/report?accommodation_id={$this->acc->id}&from={$today}&to={$today}")->assertOk();
        $ars = collect($res->json('currencies'))->firstWhere('currency', 'ARS');

        $this->assertSame(70000, $ars['totals']['operating_in']);
        $this->assertSame(10000, $ars['totals']['treasury_out']);
        $this->assertSame(70000, $ars['totals']['result']);
        $cash = collect($ars['by_method'])->firstWhere('method', 'cash');
        $this->assertSame(50000, $cash['in']);
        $this->assertSame(10000, $cash['out']);
    }

    #[Test]
    public function exporta_el_libro_en_csv(): void
    {
        $this->openShift();
        $this->record(CashCategory::STAY, 12345)->assertCreated();

        $csv = $this->get(self::BASE.'/entries/export?accommodation_id='.$this->acc->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Cobro de estadía', $csv);
        $this->assertStringContainsString('123.45', $csv);
    }

    #[Test]
    public function las_categorias_de_sistema_se_renombran_pero_no_se_desactivan(): void
    {
        $stay = $this->category(CashCategory::STAY);
        $this->patchJson(self::BASE."/categories/{$stay}", ['name' => 'Estadías'])->assertOk()->assertJsonPath('name', 'Estadías');
        $this->patchJson(self::BASE."/categories/{$stay}", ['active' => false])->assertStatus(422);

        $id = $this->postJson(self::BASE.'/categories', [
            'accommodation_id' => $this->acc->id, 'name' => 'Cochera', 'direction' => 'in',
        ])->assertCreated()->json('id');
        $this->patchJson(self::BASE."/categories/{$id}", ['active' => false])->assertOk()->assertJsonPath('active', false);
    }
}
