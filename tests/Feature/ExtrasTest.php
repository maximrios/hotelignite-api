<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Extra;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtrasTest extends TestCase
{
    use DatabaseTransactions;

    private Account $account;

    private Accommodation $acc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Cuenta extras']);
        $this->acc = $this->accommodation($this->account);
    }

    #[Test]
    public function el_hotel_arranca_con_las_plantillas_sugeridas(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->getJson("/api/v1/extras?accommodation_id={$this->acc->id}")->assertOk()
            ->assertJsonCount(0, 'extras')
            ->assertJsonCount(count(Extra::TEMPLATES), 'templates')
            ->assertJsonPath('templates.0.key', 'breakfast');
    }

    #[Test]
    public function crea_desde_una_plantilla_y_la_plantilla_deja_de_sugerirse(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->postJson('/api/v1/extras', $this->body(['template' => 'parking', 'name' => 'Cochera', 'price' => 3500.5]))
            ->assertCreated()
            ->assertJsonPath('extras.0.name', 'Cochera')
            ->assertJsonPath('extras.0.price', 3500.5)
            ->assertJsonPath('extras.0.template', 'parking')
            ->assertJsonCount(count(Extra::TEMPLATES) - 1, 'templates');

        $this->assertSame(350050, Extra::where('accommodation_id', $this->acc->id)->value('price'));
    }

    #[Test]
    public function guarda_y_quita_la_foto(): void
    {
        Sanctum::actingAs($this->owner($this->account));
        $id = $this->postJson('/api/v1/extras', $this->body(['image_url' => 'https://res.cloudinary.com/x/cuna.jpg']))
            ->assertCreated()
            ->assertJsonPath('extras.0.image_url', 'https://res.cloudinary.com/x/cuna.jpg')
            ->json('extras.0.id');

        $this->putJson("/api/v1/extras/{$id}", $this->body(['image_url' => null], post: false))->assertJsonPath('extras.0.image_url', null);
        $this->postJson('/api/v1/extras', $this->body(['image_url' => 'http://inseguro.test/x.jpg']))->assertJsonValidationErrors('image_url');
    }

    #[Test]
    public function edita_y_da_de_baja_sin_borrar_de_verdad(): void
    {
        Sanctum::actingAs($this->owner($this->account));
        $id = $this->postJson('/api/v1/extras', $this->body())->json('extras.0.id');

        $this->putJson("/api/v1/extras/{$id}", $this->body(['name' => 'Spa', 'charge_mode' => 'person_night', 'enabled' => false], post: false))
            ->assertOk()
            ->assertJsonPath('extras.0.name', 'Spa')
            ->assertJsonPath('extras.0.enabled', false);

        $this->deleteJson("/api/v1/extras/{$id}")->assertOk()->assertJsonCount(0, 'extras');
        $this->assertNotNull(Extra::withTrashed()->find($id)?->deleted_at);
    }

    #[Test]
    public function valida_precio_modalidad_y_cantidad(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->postJson('/api/v1/extras', $this->body(['price' => -1, 'charge_mode' => 'por_hora', 'max_quantity' => 50]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'charge_mode', 'max_quantity']);
    }

    #[Test]
    public function otra_cuenta_no_ve_ni_toca_los_extras(): void
    {
        $extra = Extra::create(['accommodation_id' => $this->acc->id, 'name' => 'Cochera', 'price' => 100, 'charge_mode' => Extra::MODE_STAY]);
        Sanctum::actingAs($this->owner(Account::create(['name' => 'Otra'])));

        $this->getJson("/api/v1/extras?accommodation_id={$this->acc->id}")->assertNotFound();
        $this->postJson('/api/v1/extras', $this->body())->assertNotFound();
        $this->putJson("/api/v1/extras/{$extra->id}", $this->body(post: false))->assertNotFound();
        $this->deleteJson("/api/v1/extras/{$extra->id}")->assertNotFound();
        $this->assertNull($extra->fresh()->deleted_at);
    }

    #[Test]
    public function el_hotel_quita_un_extra_de_la_reserva(): void
    {
        $id = (string) Str::uuid();
        $checkin = now()->addDays(5)->toDateString();
        $checkout = now()->addDays(7)->toDateString();
        DB::table('reservations')->insert([
            'id' => $id, 'accommodation_id' => $this->acc->id,
            'guest_id' => DB::table('guests')->value('id') ?? DB::table('guests')->insertGetId(['first_name' => 'T', 'last_name' => 'G']),
            'channel_id' => 0, 'status_id' => Reservation::STATUS_PENDING,
            'checkin_date' => $checkin, 'checkout_date' => $checkout,
            'arrival' => $checkin, 'departure' => $checkout, 'nights' => 2,
            'adults' => 2, 'childrens' => 0, 'currency_id' => 'ARS', 'currency' => 'ARS',
            'language_id' => 'es', 'package_id' => 0, 'payment_method_id' => 0, 'reservation_type' => 1,
            'total_amount' => 100000, 'created' => now(), 'modified' => now(), 'user_created' => 0,
        ]);
        $reservation = Reservation::findOrFail($id);
        $line = $reservation->extras()->create([
            'name' => 'Late check-out', 'charge_mode' => Extra::MODE_STAY, 'unit_price' => 100, 'quantity' => 1, 'units' => 1, 'total_amount' => 100,
        ]);

        Sanctum::actingAs($this->owner(Account::create(['name' => 'Otra'])));
        $this->deleteJson("/api/v1/reservations/{$reservation->id}/extras/{$line->id}")->assertNotFound();

        Sanctum::actingAs($this->owner($this->account));
        $this->deleteJson("/api/v1/reservations/{$reservation->id}/extras/{$line->id}")->assertOk();
        $this->assertSame(0, $reservation->extras()->count());
    }

    /** @param  array<string, mixed>  $overrides */
    private function body(array $overrides = [], bool $post = true): array
    {
        return [
            ...($post ? ['accommodation_id' => $this->acc->id] : []),
            'name' => 'Cuna',
            'price' => 0,
            'charge_mode' => 'stay',
            'max_quantity' => 1,
            ...$overrides,
        ];
    }

    private function accommodation(Account $account): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'name' => 'Hotel extras',
            'slug' => 'hotel-extras-'.uniqid(),
            'enabled' => 1,
        ]);
    }

    private function owner(Account $account): User
    {
        return User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
    }
}
