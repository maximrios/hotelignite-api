<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Padrón con vínculo: el staff asocia alojamientos a un client, el client los
 * invita (a tomar la ficha o a aceptar el vínculo) y el hotelero responde.
 */
class AssociateInvitationTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->cityId = (int) DB::table('cities')->value('id');
    }

    private function user(string $type, array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => $type.' '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => $type,
        ]);
    }

    private function draft(string $name, ?string $email = null): Accommodation
    {
        return Accommodation::create([
            'account_id' => null,
            'city_id' => $this->cityId,
            'name' => $name,
            'email' => $email,
            'slug' => 'test-'.uniqid(),
        ]);
    }

    /** @return array{0: Accommodation, 1: User} */
    private function claimed(string $name): array
    {
        $account = Account::create(['name' => 'Cuenta '.$name]);
        $owner = $this->user(User::TYPE_ACCOUNT, ['account_id' => $account->id]);

        $accommodation = Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $this->cityId,
            'name' => $name,
            'email' => 'contacto-'.uniqid().'@hotel.test',
            'phone' => '387 400 0000',
            'bank_data' => 'CBU secreto',
            'slug' => 'test-'.uniqid(),
        ]);

        return [$accommodation, $owner];
    }

    private function padron(Client $client, array $query = []): array
    {
        Sanctum::actingAs($this->user(User::TYPE_CLIENT, ['client_id' => $client->id]));

        return $this->getJson('/api/client-panel/v1/associates?'.http_build_query($query))->assertOk()->json();
    }

    private function invite(Client $client, array $ids, ?string $email = null): array
    {
        Sanctum::actingAs($this->user(User::TYPE_CLIENT, ['client_id' => $client->id]));

        return $this->postJson('/api/client-panel/v1/associates/invite', array_filter([
            'accommodation_ids' => $ids,
            'email' => $email,
        ]))->assertCreated()->json();
    }

    public function test_el_staff_asocia_en_masa_por_filtro_y_entran_sin_vinculo(): void
    {
        $client = Client::create(['name' => 'Agencia masiva', 'type' => Client::TYPE_AGENCY]);
        $marker = 'Masivo '.uniqid();
        $this->draft($marker.' uno');
        $this->draft($marker.' dos');
        $this->claimed($marker.' tres');

        Sanctum::actingAs($this->user(User::TYPE_PLATFORM));
        $this->postJson("/api/admin/v1/clients/{$client->id}/accommodations/bulk", ['search' => $marker])
            ->assertOk()
            ->assertJsonPath('attached', 3);

        // Re-correrlo no duplica.
        $this->postJson("/api/admin/v1/clients/{$client->id}/accommodations/bulk", ['search' => $marker])
            ->assertJsonPath('attached', 0);

        $padron = $this->padron($client);
        $this->assertSame(2, $padron['counts']['borrador']);
        $this->assertSame(1, $padron['counts']['sin_vinculo']);
        $this->assertSame(0, $padron['counts']['vinculado']);
        $this->assertSame('Con convenio', $padron['vocabulary']['agreed']);
    }

    public function test_la_asociacion_masiva_es_solo_del_staff(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        Sanctum::actingAs($this->user(User::TYPE_CLIENT, ['client_id' => $client->id]));

        $this->postJson("/api/admin/v1/clients/{$client->id}/accommodations/bulk", [])->assertForbidden();
    }

    public function test_invitar_un_borrador_usa_el_email_del_registro_o_el_que_carga_el_client(): void
    {
        $client = Client::create(['name' => 'Municipalidad Test', 'type' => Client::TYPE_GOVERNMENT]);
        $conEmail = $this->draft('Cabaña con email', 'cabana@registro.test');
        $sinEmail = $this->draft('Cabaña sin email');
        $client->accommodations()->attach([$conEmail->id, $sinEmail->id]);

        $result = $this->invite($client, [$conEmail->id, $sinEmail->id]);
        $this->assertSame([$conEmail->id], $result['sent']);
        $this->assertSame([['accommodation_id' => $sinEmail->id, 'reason' => 'no_email']], $result['skipped']);

        $result = $this->invite($client, [$sinEmail->id], 'duena@cabana.test');
        $this->assertSame([$sinEmail->id], $result['sent']);

        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('cabana@registro.test'));
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('duena@cabana.test'));

        $padron = $this->padron($client);
        $this->assertSame(2, $padron['counts']['invitado']);

        // Una vigente no se reenvía por acá.
        $again = $this->invite($client, [$conEmail->id]);
        $this->assertSame('already_invited', $again['skipped'][0]['reason']);
    }

    public function test_no_se_invita_lo_que_no_esta_en_el_padron(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        $ajeno = $this->draft('Ajeno', 'ajeno@test.com');

        $result = $this->invite($client, [$ajeno->id]);
        $this->assertSame('not_in_padron', $result['skipped'][0]['reason']);
        Mail::assertNothingQueued();
    }

    public function test_el_mail_lleva_la_frase_del_tipo_de_client(): void
    {
        $client = Client::create(['name' => 'Cámara Hotelera Test', 'type' => Client::TYPE_CHAMBER]);
        $draft = $this->draft('Hostal', 'hostal@test.com');
        $client->accommodations()->attach($draft->id);

        $this->invite($client, [$draft->id]);

        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) {
            $mail->build();

            return $mail->subject === 'Cámara Hotelera Test te invita a HotelIgnite'
                && $mail->viewData['pitch'] === 'Cámara Hotelera Test te invita a sumar Hostal como socio de la cámara.'
                && $mail->viewData['hasOwner'] === false;
        });
    }

    public function test_el_dueno_acepta_el_convenio_desde_su_bandeja_y_recien_ahi_la_agencia_ve_el_contacto(): void
    {
        $client = Client::create(['name' => 'TuriTest', 'type' => Client::TYPE_AGENCY]);
        [$hotel, $owner] = $this->claimed('Hotel Convenio');
        $client->accommodations()->attach($hotel->id);
        $clientUser = $this->user(User::TYPE_CLIENT, ['client_id' => $client->id]);

        // Sin convenio: ni contacto ni datos internos.
        Sanctum::actingAs($clientUser);
        $before = $this->getJson("/api/admin/v1/accommodations/{$hotel->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('email', $before);
        $this->assertArrayNotHasKey('phone', $before);
        $this->assertArrayNotHasKey('bank_data', $before);
        $this->assertArrayNotHasKey('account_id', $before);

        $this->invite($client, [$hotel->id]);
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo($owner->email));

        Sanctum::actingAs($owner);
        $inbox = $this->getJson('/api/admin/v1/incoming-invitations')->assertOk()->json('data');
        $this->assertCount(1, $inbox);
        $this->assertSame('TuriTest quiere trabajar con Hotel Convenio y te propone un convenio.', $inbox[0]['pitch']);

        $this->postJson("/api/admin/v1/incoming-invitations/{$inbox[0]['id']}/accept")->assertOk();
        $this->assertCount(0, $this->getJson('/api/admin/v1/incoming-invitations')->json('data'));

        $padron = $this->padron($client);
        $this->assertSame(1, $padron['counts']['vinculado']);

        Sanctum::actingAs($clientUser);
        $after = $this->getJson("/api/admin/v1/accommodations/{$hotel->id}")->assertOk()->json('data');
        $this->assertSame($hotel->email, $after['email']);
        $this->assertArrayNotHasKey('bank_data', $after);
    }

    public function test_el_borrador_muestra_el_contacto_del_registro(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        $draft = $this->draft('Borrador público', 'registro@test.com');
        $client->accommodations()->attach($draft->id);

        Sanctum::actingAs($this->user(User::TYPE_CLIENT, ['client_id' => $client->id]));
        $this->getJson("/api/admin/v1/accommodations/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'registro@test.com');
    }

    public function test_otro_hotelero_no_puede_responder_una_invitacion_ajena(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        [$hotel] = $this->claimed('Hotel Ajeno');
        [, $intruder] = $this->claimed('Otro Hotel');
        $client->accommodations()->attach($hotel->id);
        $this->invite($client, [$hotel->id]);

        $invitation = Invitation::where('accommodation_id', $hotel->id)->firstOrFail();

        Sanctum::actingAs($intruder);
        $this->assertCount(0, $this->getJson('/api/admin/v1/incoming-invitations')->json('data'));
        $this->postJson("/api/admin/v1/incoming-invitations/{$invitation->id}/accept")->assertForbidden();
    }

    public function test_si_rechaza_queda_rechazado_y_no_se_lo_puede_volver_a_invitar_por_un_tiempo(): void
    {
        $client = Client::create(['name' => 'Agencia insistente', 'type' => Client::TYPE_AGENCY]);
        [$hotel, $owner] = $this->claimed('Hotel Rechazo');
        $client->accommodations()->attach($hotel->id);
        $this->invite($client, [$hotel->id]);

        $invitation = Invitation::where('accommodation_id', $hotel->id)->firstOrFail();
        Sanctum::actingAs($owner);
        $this->postJson("/api/admin/v1/incoming-invitations/{$invitation->id}/decline")->assertOk();

        $this->assertSame(1, $this->padron($client)['counts']['rechazado']);
        $this->assertSame('declined_recently', $this->invite($client, [$hotel->id])['skipped'][0]['reason']);

        // Pasado el plazo, se puede volver a pedir.
        $invitation->forceFill(['declined_at' => now()->subDays(91)])->save();
        $this->assertSame([$hotel->id], $this->invite($client, [$hotel->id])['sent']);
    }

    public function test_reenviar_no_reabre_una_aceptada_ni_saltea_el_plazo_de_un_rechazo(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        [$aceptado, $ownerA] = $this->claimed('Hotel que aceptó');
        [$rechazo, $ownerR] = $this->claimed('Hotel que rechazó');
        $client->accommodations()->attach([$aceptado->id, $rechazo->id]);
        $this->invite($client, [$aceptado->id, $rechazo->id]);

        $invA = Invitation::where('accommodation_id', $aceptado->id)->firstOrFail();
        $invR = Invitation::where('accommodation_id', $rechazo->id)->firstOrFail();
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/admin/v1/incoming-invitations/{$invA->id}/accept")->assertOk();
        Sanctum::actingAs($ownerR);
        $this->postJson("/api/admin/v1/incoming-invitations/{$invR->id}/decline")->assertOk();

        Sanctum::actingAs($this->user(User::TYPE_CLIENT, ['client_id' => $client->id]));
        $this->postJson("/api/client-panel/v1/invitations/{$invA->id}/resend")->assertStatus(422);
        $this->postJson("/api/client-panel/v1/invitations/{$invR->id}/resend")->assertStatus(422);
        $this->assertNotNull($invA->fresh()->accepted_at);
        $this->assertNotNull($invR->fresh()->declined_at);
    }

    public function test_un_dueno_con_varios_alojamientos_recibe_una_invitacion_por_cada_uno(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        [$uno, $owner] = $this->claimed('Complejo uno');
        $dos = Accommodation::create([
            'account_id' => $uno->account_id,
            'city_id' => $this->cityId,
            'name' => 'Complejo dos',
            'slug' => 'test-'.uniqid(),
        ]);
        $client->accommodations()->attach([$uno->id, $dos->id]);

        $result = $this->invite($client, [$uno->id, $dos->id]);
        $this->assertSame([$uno->id, $dos->id], $result['sent']);

        Sanctum::actingAs($owner);
        $this->assertCount(2, $this->getJson('/api/admin/v1/incoming-invitations')->json('data'));
    }

    public function test_tomar_un_borrador_por_el_mail_tambien_deja_el_vinculo_aceptado(): void
    {
        $client = Client::create(['name' => 'Municipalidad', 'type' => Client::TYPE_GOVERNMENT]);
        $draft = $this->draft('Hostería', 'hosteria@test.com');
        $client->accommodations()->attach($draft->id);

        $this->invite($client, [$draft->id]);

        $token = null;
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Dueña', 'password' => 'secreto123'])
            ->assertOk()
            ->assertJsonPath('case', 'claim');

        $pivot = DB::table('accommodation_client')
            ->where('client_id', $client->id)
            ->where('accommodation_id', $draft->id)
            ->first();
        $this->assertNotNull($pivot->agreed_at);
        $this->assertNotNull($draft->fresh()->account_id);
    }

    public function test_el_padron_filtra_por_estado_y_pagina(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        foreach (range(1, 3) as $i) {
            $client->accommodations()->attach($this->draft("Borrador {$i}")->id);
        }
        [$hotel] = $this->claimed('Con dueño');
        $client->accommodations()->attach($hotel->id);

        $padron = $this->padron($client, ['state' => 'borrador', 'per_page' => 2]);
        $this->assertCount(2, $padron['associates']['data']);
        $this->assertSame(3, $padron['associates']['meta']['total']);
        $this->assertSame(4, $padron['counts']['total']);
        $this->assertFalse($padron['associates']['data'][0]['has_email']);

        $sinVinculo = $this->padron($client, ['state' => 'sin_vinculo']);
        $this->assertSame([$hotel->id], array_column($sinVinculo['associates']['data'], 'accommodation_id'));
        $this->assertTrue($sinVinculo['associates']['data'][0]['has_email']);
    }
}
