<?php

namespace Tests\Feature;

use App\Mail\AgencySignupVerificationMail;
use App\Models\Accommodation;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\ClientPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registro de agencias (entrega 2): alta con plan free, confirmación de email
 * para entrar y verificación del staff para invitar hoteleros.
 */
class AgencySignupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(ClientPlanSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'agency_name' => 'Viajes Test '.uniqid(),
            'legal_name' => 'Viajes Test SRL',
            'tax_id' => '30'.random_int(10000000, 99999999).'5',
            'license_number' => 'EVT 1234',
            'name' => 'Ana Agente',
            'email' => uniqid().'@agencia.test',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
        ];
    }

    /** La URL del mail lleva al portal; el portal le pasa los parámetros a la API. */
    private function verifyUrlFromMail(string $email): string
    {
        $url = null;
        Mail::assertQueued(AgencySignupVerificationMail::class, function (AgencySignupVerificationMail $mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->url;
            }

            return true;
        });

        $this->assertStringStartsWith(config('api.clients_portal_url').'/verificar?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return "/api/client-panel/v1/signup/verify/{$q['user']}/{$q['hash']}?".http_build_query([
            'expires' => $q['expires'],
            'signature' => $q['signature'],
        ]);
    }

    public function test_el_registro_crea_una_agencia_con_plan_free_y_pendiente_de_verificacion(): void
    {
        $data = $this->payload(['tax_id' => '30 71234567 9', 'email' => 'Ana@Agencia.Test']);

        $this->postJson('/api/client-panel/v1/signup', $data)
            ->assertCreated()
            ->assertJsonPath('email', 'ana@agencia.test');

        $client = Client::where('name', $data['agency_name'])->firstOrFail();
        $this->assertSame(Client::TYPE_AGENCY, $client->type);
        $this->assertSame(Client::ORIGIN_SIGNUP, $client->origin);
        $this->assertSame('30-71234567-9', $client->tax_id);
        $this->assertSame('Viajes Test SRL', $client->legal_name);
        $this->assertSame('client-agencia-free', $client->plan->slug);
        $this->assertTrue($client->needsVerification());

        $user = User::where('email', 'ana@agencia.test')->firstOrFail();
        $this->assertTrue($user->isClient());
        $this->assertSame($client->id, $user->client_id);
        $this->assertNull($user->email_verified_at);
    }

    public function test_sin_razon_social_usa_el_nombre_de_la_agencia(): void
    {
        $data = $this->payload(['legal_name' => '']);
        $this->postJson('/api/client-panel/v1/signup', $data)->assertCreated();

        $this->assertSame($data['agency_name'], Client::where('name', $data['agency_name'])->value('legal_name'));
    }

    public function test_no_entra_hasta_confirmar_el_email(): void
    {
        $data = $this->payload();
        $this->postJson('/api/client-panel/v1/signup', $data)->assertCreated();

        $login = ['email' => $data['email'], 'password' => $data['password']];
        $this->postJson('/api/auth/login', $login)
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $this->getJson($this->verifyUrlFromMail($data['email']))->assertOk();

        $this->postJson('/api/auth/login', $login)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_un_link_adulterado_no_confirma(): void
    {
        $data = $this->payload();
        $this->postJson('/api/client-panel/v1/signup', $data)->assertCreated();

        $url = $this->verifyUrlFromMail($data['email']);
        $this->getJson(preg_replace('/signature=\w+/', 'signature=falsa', $url))->assertForbidden();

        $this->assertNull(User::where('email', $data['email'])->value('email_verified_at'));
    }

    public function test_un_user_dado_de_alta_por_el_staff_entra_sin_confirmar_email(): void
    {
        $client = Client::create(['name' => 'Agencia del CRM', 'type' => Client::TYPE_AGENCY]);
        User::create([
            'name' => 'Staff alta',
            'email' => $email = uniqid().'@crm.test',
            'password' => bcrypt('secreto123'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'secreto123'])->assertOk();
    }

    public function test_el_registro_tiene_freno_por_ip(): void
    {
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/client-panel/v1/signup', $this->payload())->assertCreated();
        }

        $this->postJson('/api/client-panel/v1/signup', $this->payload())->assertTooManyRequests();
    }

    public function test_no_se_registra_dos_veces_el_mismo_cuit_ni_el_mismo_email(): void
    {
        // Son cuatro registros seguidos: el freno por IP se prueba aparte.
        $this->withoutMiddleware(ThrottleRequests::class);

        $first = $this->payload(['tax_id' => '30-71234567-9']);
        $this->postJson('/api/client-panel/v1/signup', $first)->assertCreated();

        $this->postJson('/api/client-panel/v1/signup', $this->payload(['tax_id' => '30712345679']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_id');

        $this->postJson('/api/client-panel/v1/signup', $this->payload(['email' => $first['email']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/client-panel/v1/signup', $this->payload(['tax_id' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_id');
    }

    public function test_reenviar_no_revela_si_el_email_existe(): void
    {
        $data = $this->payload();
        $this->postJson('/api/client-panel/v1/signup', $data)->assertCreated();

        $existing = $this->postJson('/api/client-panel/v1/signup/resend', ['email' => $data['email']])->assertOk()->json('message');
        $missing = $this->postJson('/api/client-panel/v1/signup/resend', ['email' => 'nadie@test.com'])->assertOk()->json('message');

        $this->assertSame($existing, $missing);
        Mail::assertQueued(AgencySignupVerificationMail::class, 2);
    }

    public function test_sin_verificacion_del_staff_no_invita_y_con_ella_si(): void
    {
        $data = $this->payload();
        $this->postJson('/api/client-panel/v1/signup', $data)->assertCreated();
        $user = User::where('email', $data['email'])->firstOrFail();
        $client = $user->client;

        $draft = Accommodation::create([
            'city_id' => (int) DB::table('cities')->value('id'),
            'name' => 'Borrador para invitar',
            'email' => 'borrador@test.com',
            'slug' => 'test-'.uniqid(),
        ]);
        $client->accommodations()->attach($draft->id);

        Sanctum::actingAs($user);
        $this->getJson('/api/client-panel/v1/me')->assertOk()->assertJsonPath('needs_verification', true)
            ->assertJsonPath('plan.slug', 'client-agencia-free')
            ->assertJsonPath('plan.free', true);
        $this->getJson('/api/client-panel/v1/associates')->assertJsonPath('can_invite', false);
        $this->postJson('/api/client-panel/v1/associates/invite', ['accommodation_ids' => [$draft->id]])->assertForbidden();
        $this->postJson('/api/client-panel/v1/invitations', ['invitations' => [['email' => 'otro@test.com']]])->assertForbidden();

        $platform = User::create([
            'name' => 'platform', 'email' => uniqid().'@hi.test', 'password' => bcrypt('x'),
            'user_type' => User::TYPE_PLATFORM,
        ]);
        Sanctum::actingAs($platform);
        $this->getJson('/api/admin/v1/clients?pending_verification=1')->assertOk()
            ->assertJsonFragment(['id' => $client->id]);
        $this->patchJson("/api/admin/v1/clients/{$client->id}", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('data.needs_verification', false);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/client-panel/v1/associates')->assertJsonPath('can_invite', true);
        $this->postJson('/api/client-panel/v1/associates/invite', ['accommodation_ids' => [$draft->id]])
            ->assertCreated()
            ->assertJsonPath('sent', [$draft->id]);
    }

    public function test_sacarle_el_plan_lo_deja_en_el_free_de_su_tipo(): void
    {
        $client = Client::create(['name' => 'Municipio', 'type' => Client::TYPE_GOVERNMENT]);
        $this->assertSame('client-padron-inicial', $client->plan->slug);

        Sanctum::actingAs(User::create([
            'name' => 'platform', 'email' => uniqid().'@hi.test', 'password' => bcrypt('x'),
            'user_type' => User::TYPE_PLATFORM,
        ]));
        $this->patchJson("/api/admin/v1/clients/{$client->id}", ['plan_id' => null, 'type' => Client::TYPE_AGENCY])->assertOk();

        $this->assertSame('client-agencia-free', $client->fresh()->plan->slug);
    }

    public function test_un_plan_gratis_no_tiene_presupuesto(): void
    {
        $client = Client::create(['name' => 'Municipio', 'type' => Client::TYPE_GOVERNMENT]);
        Sanctum::actingAs(User::create([
            'name' => 'client', 'email' => uniqid().'@test.com', 'password' => bcrypt('x'),
            'user_type' => User::TYPE_CLIENT, 'client_id' => $client->id,
        ]));

        $this->getJson('/api/client-panel/v1/billing/quote?plan=client-padron-inicial')->assertNotFound();
        $this->getJson('/api/client-panel/v1/billing/quote?plan=client-padron')->assertOk();
    }
}
