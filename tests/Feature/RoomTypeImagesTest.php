<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoomTypeImagesTest extends TestCase
{
    use DatabaseTransactions;

    private Account $account;

    private Accommodation $acc;

    private RoomType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Cuenta fotos']);
        $this->acc = $this->accommodation($this->account);
        $this->type = $this->roomType($this->acc);
    }

    #[Test]
    public function la_cuenta_duena_sube_lista_y_borra_fotos_del_tipo(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $id = $this->postJson($this->url(), ['url' => 'https://img.test/1.jpg', 'alt' => 'Vista'])
            ->assertCreated()
            ->assertJsonPath('data.order', 1)
            ->json('data.id');
        $this->postJson($this->url(), ['url' => 'https://img.test/2.jpg'])->assertJsonPath('data.order', 2);

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.url', 'https://img.test/1.jpg')
            ->assertJsonPath('data.0.alt', 'Vista');

        $this->deleteJson($this->url("/{$id}"))->assertOk();
        $this->getJson($this->url())->assertJsonCount(1, 'data');
        $this->assertSame(0, $this->acc->images()->count(), 'no se mezclan con la galería del hotel');
    }

    #[Test]
    public function reordenar_con_la_lista_de_ids_cambia_la_portada(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $a = $this->type->images()->create(['url' => 'https://img.test/a.jpg', 'order' => 0]);
        $b = $this->type->images()->create(['url' => 'https://img.test/b.jpg', 'order' => 1]);

        $this->patchJson($this->url('/reorder'), ['ids' => [$b->id, $a->id]])->assertOk()
            ->assertJsonPath('data.0.id', $b->id)
            ->assertJsonPath('data.1.id', $a->id);
    }

    #[Test]
    public function la_galeria_del_hotel_tambien_reordena_con_ids(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $a = $this->acc->images()->create(['url' => 'https://img.test/a.jpg', 'order' => 0]);
        $b = $this->acc->images()->create(['url' => 'https://img.test/b.jpg', 'order' => 1]);

        $this->patchJson("/api/admin/v1/accommodations/{$this->acc->id}/images/reorder", ['ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $b->id);
    }

    #[Test]
    public function no_se_tocan_fotos_de_otro_tipo(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $other = $this->roomType($this->acc);
        $foreign = $other->images()->create(['url' => 'https://img.test/x.jpg', 'order' => 0]);

        $this->deleteJson($this->url("/{$foreign->id}"))->assertNotFound();
        $this->patchJson($this->url('/reorder'), ['ids' => [$foreign->id]])->assertOk();
        $this->assertSame(0, (int) $foreign->fresh()->order);
    }

    #[Test]
    public function un_tipo_de_otro_alojamiento_da_404(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $foreignType = $this->roomType($this->accommodation(Account::create(['name' => 'Otra'])));

        $this->getJson("/api/admin/v1/accommodations/{$this->acc->id}/room-types/{$foreignType->id}/images")->assertNotFound();
        $this->postJson("/api/admin/v1/accommodations/{$this->acc->id}/room-types/{$foreignType->id}/images", ['url' => 'https://img.test/1.jpg'])->assertNotFound();
    }

    #[Test]
    public function otra_cuenta_no_ve_ni_sube_fotos(): void
    {
        Sanctum::actingAs($this->owner(Account::create(['name' => 'Otra'])));

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), ['url' => 'https://img.test/1.jpg'])->assertForbidden();
        $this->assertSame(0, $this->type->images()->count());
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/v1/accommodations/{$this->acc->id}/room-types/{$this->type->id}/images{$path}";
    }

    private function accommodation(Account $account): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'name' => 'Hotel fotos',
            'slug' => 'hotel-fotos-'.uniqid(),
            'enabled' => 1,
        ]);
    }

    private function roomType(Accommodation $acc): RoomType
    {
        $id = DB::table('room_types')->insertGetId([
            'accommodation_id' => $acc->id,
            'slug' => 'tipo-'.uniqid(),
            'status' => 'active',
            'max_occupancy' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return RoomType::findOrFail($id);
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
