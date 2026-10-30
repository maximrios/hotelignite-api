<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Client;
use App\Models\MemberLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cuotas de socios: categorías, emisión mensual, pagos imputados FIFO,
 * anulaciones y morosidad. Ver `DuesRepository`.
 */
class MembershipDuesTest extends TestCase
{
    use DatabaseTransactions;

    private Client $chamber;

    private Accommodation $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chamber = Client::create(['name' => 'Cámara test', 'type' => Client::TYPE_CHAMBER]);
        $this->member = $this->accommodation();
        $this->chamber->accommodations()->attach($this->member->id, ['status' => 'active', 'agreed_at' => now()]);
        $this->actAs($this->chamber);
    }

    private function accommodation(): Accommodation
    {
        return Accommodation::create([
            'account_id' => Account::create(['name' => 'Cuenta '.uniqid()])->id,
            'city_id' => (int) DB::table('cities')->value('id'),
            'name' => 'Hotel '.uniqid(),
            'slug' => 'test-'.uniqid(),
        ]);
    }

    private function actAs(Client $client): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Tesorería '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]));
    }

    private function feeFor(Accommodation $hotel, int $amount = 2_400_000): int
    {
        $fee = $this->postJson('/api/client-panel/v1/dues/fees', ['name' => 'Categoría A', 'amount' => $amount, 'due_day' => 10])
            ->assertCreated()->json('id');
        $this->postJson('/api/client-panel/v1/dues/assignments', [
            'entity_type' => 'accommodation', 'entity_ids' => [$hotel->id], 'membership_fee_id' => $fee,
        ])->assertOk();

        return $fee;
    }

    private function account(Accommodation $hotel): array
    {
        return $this->getJson("/api/client-panel/v1/dues/accounts/accommodation/{$hotel->id}")->assertOk()->json();
    }

    private function emit(string $period): array
    {
        return $this->postJson('/api/client-panel/v1/dues/emission', ['period' => $period])->assertCreated()->json();
    }

    public function test_only_chambers_get_dues(): void
    {
        $this->actAs(Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]));

        $this->getJson('/api/client-panel/v1/dues/summary')->assertForbidden();
    }

    public function test_emission_is_idempotent_and_skips_non_members(): void
    {
        $this->feeFor($this->member);
        $outsider = $this->accommodation();
        $this->chamber->accommodations()->attach($outsider->id, ['status' => 'active']); // sin vínculo
        $this->feeFor($outsider);

        $period = CarbonImmutable::today()->format('Y-m');
        $this->assertSame(1, $this->getJson("/api/client-panel/v1/dues/emission?period={$period}")->json('to_emit'));

        $this->assertSame(['emitted' => 1, 'amount' => 2_400_000], $this->emit($period));
        $this->assertSame(0, $this->emit($period)['emitted']);
    }

    public function test_reversed_fee_can_be_emitted_again(): void
    {
        $this->feeFor($this->member);
        $period = CarbonImmutable::today()->format('Y-m');
        $this->emit($period);

        $entry = $this->account($this->member)['entries'][0]['id'];
        $this->postJson("/api/client-panel/v1/dues/entries/{$entry}/reverse")->assertOk();
        $this->postJson("/api/client-panel/v1/dues/entries/{$entry}/reverse")->assertStatus(409);

        $this->assertSame(1, $this->emit($period)['emitted']);
        $this->assertSame(2_400_000, $this->account($this->member)['standing']['balance']);
    }

    public function test_payments_settle_the_oldest_dues_first_and_delinquency_counts(): void
    {
        $this->feeFor($this->member, 1_000_00);
        $today = CarbonImmutable::today();
        foreach ([4, 3, 2, 1] as $monthsAgo) {
            $this->emit($today->subMonthsNoOverflow($monthsAgo)->format('Y-m'));
        }

        $standing = $this->account($this->member)['standing'];
        $this->assertSame(4, $standing['overdue_count']);
        $this->assertSame('moroso', $standing['status']);

        // Un pago de una cuota y media cancela la más vieja y parte de la segunda.
        $this->postJson("/api/client-panel/v1/dues/accounts/accommodation/{$this->member->id}/entries", [
            'kind' => 'payment', 'amount' => 1_500_00, 'occurred_on' => $today->toDateString(), 'method' => 'transferencia',
        ])->assertCreated();

        $account = $this->account($this->member);
        $this->assertSame(2_500_00, $account['standing']['balance']);
        $this->assertSame(3, $account['standing']['overdue_count']);
        $charges = collect($account['entries'])->where('kind', 'charge')->values();
        $this->assertSame([0, 500_00, 1_000_00, 1_000_00], $charges->pluck('pending')->all());
    }

    public function test_payment_needs_a_method_and_cannot_be_future(): void
    {
        $this->postJson("/api/client-panel/v1/dues/accounts/accommodation/{$this->member->id}/entries", [
            'kind' => 'payment', 'amount' => 100, 'occurred_on' => CarbonImmutable::tomorrow()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['method', 'occurred_on']);
    }

    public function test_cannot_touch_another_clients_member(): void
    {
        $other = Client::create(['name' => 'Otra cámara', 'type' => Client::TYPE_CHAMBER]);
        $foreign = $this->accommodation();
        $other->accommodations()->attach($foreign->id, ['status' => 'active', 'agreed_at' => now()]);

        $this->getJson("/api/client-panel/v1/dues/accounts/accommodation/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/client-panel/v1/dues/accounts/accommodation/{$foreign->id}/entries", [
            'kind' => 'charge', 'amount' => 100, 'occurred_on' => CarbonImmutable::today()->toDateString(),
        ])->assertNotFound();
    }

    public function test_ledger_entries_are_append_only(): void
    {
        $this->feeFor($this->member);
        $this->emit(CarbonImmutable::today()->format('Y-m'));
        $entry = MemberLedgerEntry::where('member_id', $this->member->id)->firstOrFail();

        $this->expectException(\LogicException::class);
        $entry->update(['amount' => 1]);
    }

    public function test_padron_shows_dues_status_and_filters_debtors(): void
    {
        $this->feeFor($this->member);
        $this->emit(CarbonImmutable::today()->subMonthNoOverflow()->format('Y-m'));

        $data = $this->getJson('/api/client-panel/v1/associates?dues=con_deuda')->assertOk()->json();

        $this->assertTrue($data['charges_dues']);
        $this->assertSame([$this->member->id], collect($data['associates']['data'])->pluck('entity_id')->all());
        $this->assertSame('debe', $data['associates']['data'][0]['dues']['status']);
    }
}
