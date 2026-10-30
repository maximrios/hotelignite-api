<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** "Olvidé mi contraseña": PMS y portal de clients, nunca staff. */
class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $type, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => 'Ana Pérez',
            'email' => 'ana'.uniqid().'@test.com',
            'password' => Hash::make('vieja-123'),
            'user_type' => $type,
        ], $extra));
    }

    public function test_un_hotelero_recibe_el_link_al_pms(): void
    {
        Mail::fake();
        $user = $this->user(User::TYPE_ACCOUNT);

        $this->postJson('/api/v1/password/forgot', ['email' => strtoupper($user->email)])->assertOk();

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_starts_with($mail->url, config('api.pms_url').'/restablecer/')
                && str_contains($mail->url, '?email='.rawurlencode($user->email));
        });
    }

    public function test_un_client_recibe_el_link_al_portal(): void
    {
        Mail::fake();
        $client = Client::create(['name' => 'Cámara', 'type' => Client::TYPE_CHAMBER]);
        $user = $this->user(User::TYPE_CLIENT, ['client_id' => $client->id]);

        $this->postJson('/api/v1/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $m) => str_starts_with($m->url, config('api.clients_portal_url').'/restablecer/'));
    }

    public function test_no_revela_si_el_email_existe_ni_manda_a_staff(): void
    {
        Mail::fake();
        $staff = $this->user(User::TYPE_PLATFORM);

        $unknown = $this->postJson('/api/v1/password/forgot', ['email' => 'nadie@test.com'])->assertOk()->json('message');
        $platform = $this->postJson('/api/v1/password/forgot', ['email' => $staff->email])->assertOk()->json('message');

        $this->assertSame($unknown, $platform);
        Mail::assertNothingQueued();
    }

    public function test_un_segundo_pedido_dentro_del_minuto_no_manda_otro_mail(): void
    {
        Mail::fake();
        $user = $this->user(User::TYPE_ACCOUNT);

        $this->postJson('/api/v1/password/forgot', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertQueuedCount(1);
    }

    public function test_reset_cambia_la_clave_cierra_sesiones_y_loguea(): void
    {
        $user = $this->user(User::TYPE_ACCOUNT);
        $user->createToken('vieja');
        $token = Password::broker()->createToken($user);

        $res = $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'nueva-1234',
            'password_confirmation' => 'nueva-1234',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('nueva-1234', $user->password));
        $this->assertNotNull($user->email_verified_at);
        // Sólo queda el token recién emitido.
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotEmpty($res->json('token'));

        // El link sirve una sola vez.
        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'otra-12345',
            'password_confirmation' => 'otra-12345',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_reset_rechaza_token_ajeno_o_inventado(): void
    {
        $ana = $this->user(User::TYPE_ACCOUNT);
        $otro = $this->user(User::TYPE_ACCOUNT);
        $token = Password::broker()->createToken($otro);

        foreach ([$token, 'inventado'] as $t) {
            $this->postJson('/api/v1/password/reset', [
                'email' => $ana->email,
                'token' => $t,
                'password' => 'nueva-1234',
                'password_confirmation' => 'nueva-1234',
            ])->assertStatus(422)->assertJsonValidationErrors('token');
        }
        $this->assertTrue(Hash::check('vieja-123', $ana->fresh()->password));
    }
}
