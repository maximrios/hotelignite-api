<?php

namespace Tests\Feature;

use App\Mail\UserWelcomeMail;
use App\Models\Client;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Invitación de acceso de users B2B dados de alta desde el CRM: email de
 * bienvenida, activación desde `/activar/{token}`, recordatorio y reenvío.
 */
class UserInvitationTest extends TestCase
{
    use DatabaseTransactions;

    private Client $client;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->client = Client::create(['name' => 'Norte Andino Viajes', 'type' => Client::TYPE_AGENCY]);
        $this->platform = User::create([
            'name' => 'Martín Arias',
            'email' => 'staff-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_PLATFORM,
        ]);
    }

    private function invitePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lucía Paz',
            'email' => 'lucia-'.uniqid().'@norteandino.test',
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $this->client->id,
            'send_invitation' => true,
        ], $overrides);
    }

    /** Da de alta un user invitado desde el CRM y devuelve el token del email. */
    private function inviteUser(): array
    {
        Sanctum::actingAs($this->platform);
        $email = $this->invitePayload()['email'];

        $this->postJson('/api/admin/v1/users', $this->invitePayload(['email' => $email]))->assertCreated();

        $user = User::where('email', $email)->firstOrFail();

        return [$user, $this->lastTokenFor($email)];
    }

    private function lastTokenFor(string $email): string
    {
        $token = null;
        Mail::assertQueued(UserWelcomeMail::class, function (UserWelcomeMail $mail) use ($email, &$token) {
            if ($mail->hasTo($email)) {
                $token = $mail->token;
            }

            return true;
        });

        $this->assertNotNull($token);

        return $token;
    }

    public function test_alta_con_invitacion_crea_el_user_sin_contrasena_usable_y_encola_el_email(): void
    {
        [$user, $token] = $this->inviteUser();

        $invitation = $user->latestInvitation;
        $this->assertNotNull($invitation);
        $this->assertSame(UserInvitation::hashToken($token), $invitation->token_hash);
        $this->assertSame($this->platform->id, $invitation->created_by_user_id);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp, 5);

        // Hasta activar, no hay contraseña con la que entrar.
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => ''])->assertStatus(422);
        $this->assertFalse(Hash::check('password123', $user->password));
    }

    public function test_el_alta_devuelve_el_estado_de_la_invitacion(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->invitePayload())
            ->assertCreated()
            ->assertJsonPath('data.invitation.status', UserInvitation::STATUS_PENDING);
    }

    public function test_con_invitacion_no_se_acepta_contrasena_manual(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->invitePayload([
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_sin_invitacion_la_contrasena_sigue_siendo_obligatoria(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->invitePayload(['send_invitation' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        Mail::assertNothingQueued();
    }

    public function test_la_invitacion_por_email_es_solo_para_clients(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->invitePayload([
            'user_type' => User::TYPE_PLATFORM,
            'client_id' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors('send_invitation');
    }

    public function test_el_email_lleva_al_portal_de_clients_y_nombra_al_client(): void
    {
        [$user, $token] = $this->inviteUser();

        $html = (new UserWelcomeMail($user->latestInvitation, $token))->render();

        $this->assertStringContainsString(config('api.clients_portal_url').'/activar/'.$token, $html);
        $this->assertStringContainsString('Norte Andino Viajes', $html);
        $this->assertStringContainsString('Hola Lucía', $html);
        $this->assertStringContainsString('Martín Arias', $html);
    }

    public function test_ver_el_link_muestra_a_quien_pertenece_sin_contar_la_apertura(): void
    {
        [$user, $token] = $this->inviteUser();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/user-invitations/{$token}")
            ->assertOk()
            ->assertJson([
                'status' => UserInvitation::STATUS_PENDING,
                'name' => 'Lucía Paz',
                'email' => $user->email,
                'client_name' => 'Norte Andino Viajes',
            ]);

        // El GET lo hacen también los escáneres de links del correo.
        $this->assertNull($user->latestInvitation->fresh()->opened_at);
    }

    public function test_la_apertura_se_registra_una_vez(): void
    {
        [$user, $token] = $this->inviteUser();

        $this->postJson("/api/v1/user-invitations/{$token}/open")->assertNoContent();
        $openedAt = $user->latestInvitation->fresh()->opened_at;
        $this->assertNotNull($openedAt);
        $this->getJson("/api/v1/user-invitations/{$token}")->assertJsonPath('status', UserInvitation::STATUS_OPENED);

        // La métrica es la primera apertura: volver a abrir no la mueve.
        $this->travel(1)->hours();
        $this->postJson("/api/v1/user-invitations/{$token}/open")->assertNoContent();
        $this->assertEquals($openedAt, $user->latestInvitation->fresh()->opened_at);
    }

    public function test_aceptar_fija_la_contrasena_verifica_el_email_y_deja_logueado(): void
    {
        [$user, $token] = $this->inviteUser();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/user-invitations/{$token}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertOk()->assertJsonStructure(['token', 'token_type']);

        $user->refresh();
        $this->assertTrue(Hash::check('mi-clave-nueva', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(UserInvitation::STATUS_ACCEPTED, $user->latestInvitation->status());

        // El login usa el guard web; Sanctum::actingAs lo dejó en sanctum.
        $this->app['auth']->shouldUse('web');
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'mi-clave-nueva'])->assertOk();
    }

    public function test_el_link_sirve_una_sola_vez(): void
    {
        [, $token] = $this->inviteUser();
        $payload = ['password' => 'mi-clave-nueva', 'password_confirmation' => 'mi-clave-nueva'];

        $this->postJson("/api/v1/user-invitations/{$token}/accept", $payload)->assertOk();
        $this->postJson("/api/v1/user-invitations/{$token}/accept", $payload)->assertStatus(410);

        // Ya usado, el link no dice de quién es.
        $this->getJson("/api/v1/user-invitations/{$token}")
            ->assertOk()
            ->assertExactJson(['status' => UserInvitation::STATUS_ACCEPTED]);
    }

    public function test_un_link_vencido_no_sirve(): void
    {
        [$user, $token] = $this->inviteUser();
        $user->latestInvitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson("/api/v1/user-invitations/{$token}")->assertExactJson(['status' => UserInvitation::STATUS_EXPIRED]);
        $this->postJson("/api/v1/user-invitations/{$token}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertStatus(410);
    }

    public function test_un_token_desconocido_da_404(): void
    {
        $this->getJson('/api/v1/user-invitations/'.str_repeat('x', 64))->assertNotFound();
    }

    public function test_el_recordatorio_sale_a_los_3_dias_una_sola_vez_y_rota_el_token(): void
    {
        [$user, $oldToken] = $this->inviteUser();
        $invitation = $user->latestInvitation;

        $this->artisan('users:remind-invitations')->expectsOutput('Recordatorios enviados: 0');

        $invitation->forceFill(['sent_at' => now()->subDays(3)->subMinute()])->save();
        $expiresAt = $invitation->fresh()->expires_at;

        $this->artisan('users:remind-invitations')->expectsOutput('Recordatorios enviados: 1');
        $this->artisan('users:remind-invitations')->expectsOutput('Recordatorios enviados: 0');

        $reminder = null;
        Mail::assertQueued(UserWelcomeMail::class, function (UserWelcomeMail $mail) use (&$reminder) {
            if ($mail->reminder) {
                $reminder = $mail;
            }

            return true;
        });
        $this->assertNotNull($reminder);
        $this->assertNotSame($oldToken, $reminder->token);

        // Misma fila, mismo vencimiento: el recordatorio no estira el plazo.
        $invitation->refresh();
        $this->assertNotNull($invitation->reminded_at);
        $this->assertEquals($expiresAt, $invitation->expires_at);

        $this->getJson("/api/v1/user-invitations/{$oldToken}")->assertNotFound();
        $this->getJson("/api/v1/user-invitations/{$reminder->token}")->assertOk();
    }

    public function test_no_se_recuerda_a_quien_ya_activo(): void
    {
        [$user, $token] = $this->inviteUser();
        $this->postJson("/api/v1/user-invitations/{$token}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertOk();

        $user->latestInvitation->forceFill(['sent_at' => now()->subDays(4)])->save();

        $this->artisan('users:remind-invitations')->expectsOutput('Recordatorios enviados: 0');
    }

    public function test_reenviar_desde_el_crm_invalida_el_link_anterior(): void
    {
        [$user, $oldToken] = $this->inviteUser();
        $oldInvitation = $user->latestInvitation;

        $this->postJson("/api/admin/v1/users/{$user->id}/invitation")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', UserInvitation::STATUS_PENDING);

        $this->assertNotNull($oldInvitation->fresh()->revoked_at);
        $this->assertSame(2, $user->invitations()->count());
        $this->postJson("/api/v1/user-invitations/{$oldToken}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertStatus(410);
    }

    public function test_no_se_reenvia_a_quien_ya_activo(): void
    {
        [$user, $token] = $this->inviteUser();
        $this->postJson("/api/v1/user-invitations/{$token}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertOk();

        Sanctum::actingAs($this->platform);
        $this->postJson("/api/admin/v1/users/{$user->id}/invitation")->assertStatus(409);
    }

    public function test_se_puede_invitar_a_un_user_existente_con_contrasena_manual(): void
    {
        $user = User::create([
            'name' => 'Ana Existente',
            'email' => 'ana-'.uniqid().'@norteandino.test',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $this->client->id,
        ]);
        Sanctum::actingAs($this->platform);

        $this->postJson("/api/admin/v1/users/{$user->id}/invitation")->assertOk();

        Mail::assertQueued(UserWelcomeMail::class, fn (UserWelcomeMail $mail) => $mail->hasTo($user->email));
    }

    public function test_solo_el_staff_reenvia_invitaciones(): void
    {
        [$user] = $this->inviteUser();

        Sanctum::actingAs($user);
        $this->postJson("/api/admin/v1/users/{$user->id}/invitation")->assertForbidden();
    }

    public function test_dar_de_baja_al_user_invalida_su_link(): void
    {
        [$user, $token] = $this->inviteUser();

        $this->deleteJson("/api/admin/v1/users/{$user->id}")->assertNoContent();

        $this->assertNotNull(UserInvitation::where('user_id', $user->id)->first()->revoked_at);
        $this->getJson("/api/v1/user-invitations/{$token}")->assertNotFound();
        $this->postJson("/api/v1/user-invitations/{$token}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertNotFound();
    }

    public function test_cargar_la_contrasena_a_mano_revoca_el_link_pendiente(): void
    {
        [$user, $token] = $this->inviteUser();

        $this->patchJson("/api/admin/v1/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $this->client->id,
            'password' => 'otra-clave-123',
            'password_confirmation' => 'otra-clave-123',
        ])->assertOk()->assertJsonPath('data.invitation.status', UserInvitation::STATUS_REVOKED);

        $this->postJson("/api/v1/user-invitations/{$token}/accept", [
            'password' => 'mi-clave-nueva',
            'password_confirmation' => 'mi-clave-nueva',
        ])->assertStatus(410);
    }
}
