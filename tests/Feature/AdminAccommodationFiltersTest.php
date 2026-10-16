<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filtros del listado del CRM (`GET admin/v1/accommodations`): ciudad, tipo,
 * borrador/reclamado y el tope de `limit`. Ciudad y tipo se crean propios para
 * que el resultado no dependa de lo que traiga la base de tests.
 */
class AdminAccommodationFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    private int $typeId;

    private Accommodation $draft;

    private Accommodation $claimed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Ciudad filtros', 'slug' => 'ciudad-filtros-'.uniqid()]);
        $this->typeId = DB::table('accommodation_types')->insertGetId(['name' => 'Tipo filtros '.uniqid()]);
        $otherTypeId = DB::table('accommodation_types')->insertGetId(['name' => 'Otro tipo '.uniqid()]);

        $this->draft = Accommodation::create([
            'account_id' => null,
            'city_id' => $this->cityId,
            'type_id' => $this->typeId,
            'name' => 'Borrador filtros',
        ]);
        $this->claimed = Accommodation::create([
            'account_id' => Account::create(['name' => 'Cuenta filtros'])->id,
            'city_id' => $this->cityId,
            'type_id' => $otherTypeId,
            'name' => 'Reclamado filtros',
        ]);

        Sanctum::actingAs(User::create([
            'name' => 'platform filtros',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]));
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        return collect($this->getJson("/api/admin/v1/accommodations?{$query}")->assertOk()->json('data'))
            ->pluck('id')
            ->all();
    }

    public function test_filtra_por_ciudad(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->draft->id, $this->claimed->id],
            $this->ids("city_id={$this->cityId}"),
        );
    }

    public function test_filtra_por_tipo(): void
    {
        $this->assertSame([$this->draft->id], $this->ids("city_id={$this->cityId}&type_id={$this->typeId}"));
    }

    public function test_filtra_borradores_y_reclamados(): void
    {
        $this->assertSame([$this->draft->id], $this->ids("city_id={$this->cityId}&owner=draft"));
        $this->assertSame([$this->claimed->id], $this->ids("city_id={$this->cityId}&owner=claimed"));
    }

    public function test_limit_tiene_tope(): void
    {
        $this->getJson('/api/admin/v1/accommodations?limit=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_resuelve_una_ciudad_por_id(): void
    {
        $this->getJson("/api/admin/v1/cities?id={$this->cityId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ciudad filtros');
    }
}
