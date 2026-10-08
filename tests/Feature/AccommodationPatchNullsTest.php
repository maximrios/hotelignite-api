<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El PATCH del PMS manda `""` en los campos vacíos, `ConvertEmptyStringsToNull`
 * los vuelve `null` y la validación los acepta (`nullable`). Las columnas legacy
 * son `NOT NULL DEFAULT '0'`: sin el saneo de `Accommodation`, eso era un 500.
 */
class AccommodationPatchNullsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vaciar_campos_legacy_not_null_guarda_el_default_en_vez_de_fallar(): void
    {
        $account = Account::create(['name' => 'Cuenta patch nulls']);
        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $accommodation = Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $cityId,
            'name' => 'Aloj '.uniqid(),
            'country_id' => 'AR',
            'phone' => '3874000000',
            'latitude' => '-24.7883',
            'longitude' => '-65.4106',
        ]);

        Sanctum::actingAs(User::create([
            'name' => 'Hotelero',
            'email' => 'acc-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]));

        // Lo que manda el LocationPanel del PMS con país y coordenadas vacíos.
        $this->patchJson("/api/admin/v1/accommodations/{$accommodation->id}", [
            'address' => 'Caseros 549',
            'country_id' => '',
            'postal_code' => '',
            'state_id' => null,
            'latitude' => null,
            'longitude' => null,
            'phone' => '',
        ])->assertOk();

        $row = DB::table('accommodations')->where('id', $accommodation->id)->first();
        $this->assertSame('Caseros 549', $row->address);
        $this->assertSame('0', $row->country_id);
        $this->assertSame('0', $row->postal_code);
        $this->assertSame(0, $row->state_id);
        $this->assertSame('0', $row->phone);
        $this->assertSame('0', $row->latitude);
        $this->assertNull($row->location);
    }
}
