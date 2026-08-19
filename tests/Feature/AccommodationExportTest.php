<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Export CSV del padrón del client (`GET client-panel/v1/accommodations/export`).
 *
 * Usa DatabaseTransactions —no RefreshDatabase— por lo mismo que
 * AccommodationTenancyTest: el schema viene de un dump, no de migraciones.
 */
class AccommodationExportTest extends TestCase
{
    use DatabaseTransactions;

    private Client $client;

    private Accommodation $mine;

    private Accommodation $other;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Cuenta export test']);
        $cityId = $this->anyCityId();

        $this->mine = Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $cityId,
            'name' => 'Hotel Del Padrón',
            'slug' => 'hotel-padron-'.uniqid(),
            'enabled' => true,
        ]);
        $this->other = Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $cityId,
            'name' => 'Hotel Ajeno',
            'slug' => 'hotel-ajeno-'.uniqid(),
            'enabled' => false,
        ]);

        $this->client = Client::create(['name' => 'Cámara test']);
        // Solo el primero entra al pivote del client: el otro no debe exportarse.
        $this->client->accommodations()->attach($this->mine->id);
    }

    private function anyCityId(): int
    {
        return City::query()->value('id')
            ?? DB::table('cities')->insertGetId([
                'name' => 'Ciudad test',
                'slug' => 'ciudad-test-'.uniqid(),
            ]);
    }

    private function clientUser(): User
    {
        return User::create([
            'name' => 'client user',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $this->client->id,
        ]);
    }

    /** @test */
    public function exporta_solo_el_padron_del_client_como_csv(): void
    {
        Sanctum::actingAs($this->clientUser());

        $res = $this->get('/api/client-panel/v1/accommodations/export');

        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Debe llevar BOM UTF-8');
        $this->assertStringContainsString('Hotel Del Padrón', $body);
        $this->assertStringNotContainsString('Hotel Ajeno', $body, 'No exporta lo que no está en su pivote');
    }

    /** @test */
    public function nunca_expone_datos_internos_del_hotelero(): void
    {
        // bank_data/tax_identification viven en el Resource de admin, no acá.
        $this->mine->update([
            'bank_data' => 'CBU-SECRETO-123',
            'tax_identification' => '30-99999999-9',
        ]);

        Sanctum::actingAs($this->clientUser());

        $body = $this->get('/api/client-panel/v1/accommodations/export')->streamedContent();

        $this->assertStringNotContainsString('CBU-SECRETO-123', $body);
        $this->assertStringNotContainsString('30-99999999-9', $body);
    }

    /** @test */
    public function respeta_el_filtro_enabled(): void
    {
        // Un segundo alojamiento habilitado en el mismo padrón para contrastar.
        $enabled = Accommodation::create([
            'account_id' => $this->mine->account_id,
            'city_id' => $this->mine->city_id,
            'name' => 'Hotel Habilitado',
            'slug' => 'hotel-hab-'.uniqid(),
            'enabled' => true,
        ]);
        $this->client->accommodations()->attach($enabled->id);
        // Y uno deshabilitado, también en el pivote.
        $disabled = Accommodation::create([
            'account_id' => $this->mine->account_id,
            'city_id' => $this->mine->city_id,
            'name' => 'Hotel Deshabilitado',
            'slug' => 'hotel-des-'.uniqid(),
            'enabled' => false,
        ]);
        $this->client->accommodations()->attach($disabled->id);

        Sanctum::actingAs($this->clientUser());

        $body = $this->get('/api/client-panel/v1/accommodations/export?enabled=1')->streamedContent();

        $this->assertStringContainsString('Hotel Habilitado', $body);
        $this->assertStringNotContainsString('Hotel Deshabilitado', $body);
    }

    /** @test */
    public function honra_columnas_separador_y_sin_encabezados(): void
    {
        Sanctum::actingAs($this->clientUser());

        $body = $this->get(
            '/api/client-panel/v1/accommodations/export?columns=name&separator=,&headers=0',
        )->streamedContent();

        // Sin encabezados: no aparece la etiqueta de columna.
        $this->assertStringNotContainsString('Nombre', $body);
        // Solo la columna pedida: el slug no debe estar.
        $this->assertStringNotContainsString($this->mine->slug, $body);
        $this->assertStringContainsString('Hotel Del Padrón', $body);
    }

    /** @test */
    public function un_user_no_client_no_entra(): void
    {
        $account = Account::create(['name' => 'Cuenta hotelera test']);
        $hotelero = User::create([
            'name' => 'hotelero',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
        Sanctum::actingAs($hotelero);

        // El grupo client-panel está detrás de `client.user`: un account rebota.
        $this->get('/api/client-panel/v1/accommodations/export')->assertForbidden();
    }
}
