<?php

namespace Tests\Feature;

use App\Mail\InquiryReceivedMail;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\Experience;
use App\Models\ExperienceCategory;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consultas polimórficas (docs/experiences-plan.md): el portal las crea sobre un
 * alojamiento de su padrón o una experiencia suya; el hotelero sigue viendo las
 * de sus alojamientos como antes, y el client ve las suyas en su panel.
 */
class InquiryTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    private Client $portal;

    private Account $account;

    private Accommodation $accommodation;

    private Experience $experience;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Salta test', 'slug' => 'salta-test-'.uniqid()]);
        $this->portal = Client::create(['name' => 'Portal test', 'type' => Client::TYPE_GOVERNMENT, 'active' => true]);
        $this->key = ClientApiKey::generateFor($this->portal, 'inquiry test', ['catalog:read', 'inquiry:create'])['plain'];

        $this->account = Account::create(['name' => 'Cuenta consultas']);
        $this->accommodation = $this->makeAccommodation($this->account);
        $this->accommodation->clients()->attach($this->portal->id, ['status' => 'active', 'verified_at' => now()]);

        $this->experience = $this->makeExperience($this->portal);
    }

    private function makeAccommodation(Account $account): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $this->cityId,
            'name' => 'Aloj '.uniqid(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeExperience(?Client $client, array $overrides = []): Experience
    {
        $category = ExperienceCategory::query()->first()
            ?? ExperienceCategory::create(['slug' => 'test-'.uniqid(), 'name' => 'Categoría test']);

        $experience = new Experience(array_merge([
            'experience_category_id' => $category->id,
            'departure_city_id' => $this->cityId,
            'name' => 'Experiencia '.uniqid(),
            'status' => Experience::STATUS_PUBLISHED,
        ], $overrides));
        $experience->client_id = $client?->id;
        $experience->save();

        return $experience;
    }

    private function makeUser(string $type, ?Account $account = null, ?Client $client = null): User
    {
        return User::create([
            'name' => 'Test '.$type,
            'email' => $type.'-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'account_id' => $account?->id,
            'client_id' => $client?->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana',
            'lastname' => 'Pérez',
            'email' => 'ana@example.com',
            'phone' => '+54 387 555 0000',
            'adults' => 2,
        ], $overrides);
    }

    private function portal(array $payload, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/client/v1/inquiries', $payload, ['Authorization' => 'Bearer '.($key ?? $this->key)]);
    }

    // --- Portal ---------------------------------------------------------------

    public function test_el_portal_consulta_por_una_experiencia_suya(): void
    {
        $this->portal($this->payload(['experience_id' => $this->experience->id, 'checkin' => now()->addWeek()->toDateString()]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'experience')
            ->assertJsonPath('data.experience_id', $this->experience->id)
            ->assertJsonPath('data.accommodation_id', null)
            ->assertJsonPath('data.client_id', $this->portal->id)
            ->assertJsonPath('data.inquirable.name', $this->experience->name);

        $this->assertDatabaseHas('inquiries', [
            'inquirable_type' => Experience::class,
            'inquirable_id' => $this->experience->id,
            'client_id' => $this->portal->id,
        ]);
    }

    public function test_sin_la_ability_es_403(): void
    {
        $readOnly = ClientApiKey::generateFor($this->portal, 'solo lectura', ['catalog:read'])['plain'];

        $this->portal($this->payload(['experience_id' => $this->experience->id]), $readOnly)->assertForbidden();
    }

    public function test_experiencia_ajena_o_en_borrador_es_404(): void
    {
        $other = $this->makeExperience(Client::create(['name' => 'Otro', 'type' => Client::TYPE_AGENCY]));
        $draft = $this->makeExperience($this->portal, ['status' => Experience::STATUS_DRAFT]);

        $this->portal($this->payload(['experience_id' => $other->id]))->assertNotFound();
        $this->portal($this->payload(['experience_id' => $draft->id]))->assertNotFound();
        $this->assertDatabaseMissing('inquiries', ['inquirable_type' => Experience::class]);
    }

    public function test_consulta_por_alojamiento_del_padron(): void
    {
        $checkin = now()->addDays(10)->toDateString();

        $this->portal($this->payload(['accommodation_id' => $this->accommodation->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checkin');

        $this->portal($this->payload([
            'accommodation_id' => $this->accommodation->id,
            'checkin' => $checkin,
            'checkout' => now()->addDays(12)->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'accommodation')
            ->assertJsonPath('data.accommodation_id', $this->accommodation->id);

        $foreign = $this->makeAccommodation(Account::create(['name' => 'Ajena']));
        $this->portal($this->payload(['accommodation_id' => $foreign->id, 'checkin' => $checkin]))->assertNotFound();
    }

    public function test_una_consulta_del_portal_queda_como_portal_y_avisa_al_hotelero(): void
    {
        Mail::fake();
        $owner = $this->makeUser(User::TYPE_ACCOUNT, $this->account);
        $scopedElsewhere = $this->makeUser(User::TYPE_ACCOUNT, $this->account);
        $scopedElsewhere->accommodations()->attach($this->makeAccommodation($this->account)->id);

        $this->portal($this->payload([
            'accommodation_id' => $this->accommodation->id,
            'checkin' => now()->addDays(10)->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.source', 'portal');

        Mail::assertQueued(InquiryReceivedMail::class, fn (InquiryReceivedMail $mail) => $mail->hasTo($owner->email));
        // Un usuario acotado a otro alojamiento de la cuenta no se entera.
        Mail::assertNotQueued(InquiryReceivedMail::class, fn (InquiryReceivedMail $mail) => $mail->hasTo($scopedElsewhere->email));
    }

    public function test_una_consulta_por_experiencia_avisa_al_client(): void
    {
        Mail::fake();
        $clientUser = $this->makeUser(User::TYPE_CLIENT, null, $this->portal);

        $this->portal($this->payload(['experience_id' => $this->experience->id]))->assertCreated();

        Mail::assertQueued(InquiryReceivedMail::class, fn (InquiryReceivedMail $mail) => $mail->hasTo($clientUser->email));
    }

    public function test_alojamiento_y_experiencia_a_la_vez_es_invalido(): void
    {
        $this->portal($this->payload([
            'accommodation_id' => $this->accommodation->id,
            'experience_id' => $this->experience->id,
            'checkin' => now()->addDay()->toDateString(),
        ]))->assertUnprocessable();

        $this->portal($this->payload())->assertUnprocessable()->assertJsonValidationErrors(['accommodation_id', 'experience_id']);
    }

    // --- Lectura --------------------------------------------------------------

    private function inquiryFor(Model $inquirable): Inquiry
    {
        $inquiry = new Inquiry;
        $inquiry->inquirable()->associate($inquirable);
        $inquiry->name = 'Ana';
        $inquiry->lastname = 'Pérez';
        $inquiry->email = 'ana@example.com';
        $inquiry->phone = '123456';
        $inquiry->adults = 2;
        $inquiry->childrens = 0;
        $inquiry->save();

        return $inquiry;
    }

    public function test_el_hotelero_ve_las_de_sus_alojamientos_como_antes(): void
    {
        $mine = $this->inquiryFor($this->accommodation);
        $ofExperience = $this->inquiryFor($this->experience);
        $foreign = $this->inquiryFor($this->makeAccommodation(Account::create(['name' => 'Ajena'])));
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT, $this->account));

        $ids = collect($this->getJson('/api/v1/inquiries?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($ofExperience->id));
        $this->assertFalse($ids->contains($foreign->id));

        // El PMS filtra y lee por `accommodation_id`.
        $this->getJson("/api/v1/inquiries?accommodation_id={$this->accommodation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.accommodation_id', $this->accommodation->id);
    }

    public function test_el_client_ve_y_marca_las_suyas_en_su_panel(): void
    {
        $ofExperience = $this->inquiryFor($this->experience);
        $ofPadron = $this->inquiryFor($this->accommodation);
        $other = $this->inquiryFor($this->makeExperience(Client::create(['name' => 'Otro', 'type' => Client::TYPE_AGENCY])));
        Sanctum::actingAs($this->makeUser(User::TYPE_CLIENT, client: $this->portal));

        $ids = collect($this->getJson('/api/client-panel/v1/inquiries')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$ofExperience->id, $ofPadron->id], $ids->all());

        $this->getJson('/api/client-panel/v1/inquiries?type=experience')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inquirable.name', $this->experience->name);

        $this->patchJson("/api/client-panel/v1/inquiries/{$ofExperience->id}", ['status' => 'answered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered');
        $this->patchJson("/api/client-panel/v1/inquiries/{$other->id}", ['status' => 'answered'])->assertNotFound();
    }

    public function test_el_v1_legacy_acepta_una_experiencia_sin_checkin(): void
    {
        Sanctum::actingAs($this->makeUser(User::TYPE_PLATFORM));

        $this->postJson('/api/v1/inquiries', $this->payload(['experience_id' => $this->experience->id, 'childrens' => 0]))
            ->assertCreated();
        $this->postJson('/api/v1/inquiries', $this->payload(['accommodation_id' => $this->accommodation->id, 'childrens' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checkin');
    }
}
