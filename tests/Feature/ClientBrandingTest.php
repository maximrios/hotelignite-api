<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doble marca del client: sube su logo y la invitación sale con su nombre, su
 * logo (rasterizado por Cloudinary) y las respuestas a su email.
 */
class ClientBrandingTest extends TestCase
{
    use DatabaseTransactions;

    private const LOGO = 'https://res.cloudinary.com/demo/image/upload/v1/hotelignite/logos/camara.svg';

    private function actAs(Client $client): User
    {
        $user = User::create([
            'name' => 'Client '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_client_sets_and_removes_its_logo(): void
    {
        $client = Client::create(['name' => 'Cámara Hotelera de Salta', 'type' => Client::TYPE_CHAMBER]);
        $this->actAs($client);

        $me = $this->patchJson('/api/client-panel/v1/me/branding', ['logo_url' => self::LOGO, 'logo_public_id' => 'hotelignite/logos/camara'])
            ->assertOk()->json();

        $this->assertSame(self::LOGO, $me['logo_url']);
        // El mail y el PMS usan la versión PNG, nunca el SVG crudo.
        $this->assertStringContainsString('/image/upload/f_png,h_96,c_fit,q_auto/v1/', $me['brand']['logo_url']);
        $this->assertSame('CH', $me['brand']['initials']);

        $this->patchJson('/api/client-panel/v1/me/branding', ['logo_url' => null])->assertOk();
        $this->assertNull($client->fresh()->logo_public_id);
    }

    public function test_logo_must_come_from_cloudinary(): void
    {
        $this->actAs(Client::create(['name' => 'Cámara', 'type' => Client::TYPE_CHAMBER]));

        foreach (['http://res.cloudinary.com/demo/image/upload/x.png', 'https://evil.example/logo.png', 'https://res.cloudinary.com/demo/raw/upload/x.svg'] as $url) {
            $this->patchJson('/api/client-panel/v1/me/branding', ['logo_url' => $url])
                ->assertStatus(422)->assertJsonValidationErrors('logo_url');
        }
    }

    public function test_invitation_mail_is_cobranded(): void
    {
        Mail::fake();
        $client = Client::create([
            'name' => 'Cámara Hotelera de Salta',
            'type' => Client::TYPE_CHAMBER,
            'email' => 'socios@camara.test',
            'logo_url' => self::LOGO,
        ]);
        $this->actAs($client);

        $this->postJson('/api/client-panel/v1/invitations', [
            'invitations' => [['email' => 'hotel@test.com', 'contact_name' => 'Ana']],
        ])->assertSuccessful();

        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) {
            $mail->build();
            $html = $mail->render();

            return $mail->hasFrom(config('mail.from.address'), 'Cámara Hotelera de Salta vía HotelIgnite')
                && $mail->hasReplyTo('socios@camara.test')
                && $mail->subject === 'Cámara Hotelera de Salta te invita a asociarte'
                && str_contains($html, 'f_png,h_96,c_fit')
                && str_contains($html, 'Con la tecnología de');
        });
    }

    public function test_invitation_landing_exposes_the_brand(): void
    {
        $client = Client::create(['name' => 'Municipalidad de Cafayate', 'type' => Client::TYPE_GOVERNMENT, 'logo_url' => self::LOGO]);
        $token = 'tok-'.uniqid();
        Invitation::create([
            'client_id' => $client->id,
            'email' => 'x@test.com',
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(10),
        ]);

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('client_brand.name', 'Municipalidad de Cafayate')
            ->assertJsonPath('client_brand.initials', 'MC');
    }
}
