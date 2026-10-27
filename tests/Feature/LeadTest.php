<?php

namespace Tests\Feature;

use App\Mail\LeadReceivedMail;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Leads de la landing: "Empezá gratis" y "Pedí una demo" guardan el prospecto
 * y avisan al staff. No crean usuarios.
 */
class LeadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['api.leads_notify_email' => 'ventas@hotelignite.test', 'services.turnstile.secret_key' => null]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'type' => Lead::TYPE_SIGNUP,
            'name' => 'Laura Hotelera',
            'email' => '  Laura@Hostal.TEST ',
            'phone' => '+54 9 387 555-1234',
            'accommodation_name' => 'Hostal del Cerro',
            'city' => 'Cafayate',
            'rooms' => 12,
        ];
    }

    public function test_signup_lead_is_stored_and_staff_is_notified(): void
    {
        $this->postJson('/api/v1/leads', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Recibimos tus datos. Te contactamos para activar tu cuenta.');

        $lead = Lead::where('email', 'laura@hostal.test')->firstOrFail();
        $this->assertSame('new', $lead->fresh()->status);
        $this->assertSame('web', $lead->fresh()->source);
        $this->assertSame(12, $lead->rooms);

        Mail::assertQueued(LeadReceivedMail::class, fn (LeadReceivedMail $mail) => $mail->hasTo('ventas@hotelignite.test')
            && $mail->lead->is($lead));
    }

    public function test_demo_lead_does_not_require_accommodation_name(): void
    {
        $this->postJson('/api/v1/leads', $this->payload([
            'type' => Lead::TYPE_DEMO,
            'accommodation_name' => null,
            'message' => 'Tengo dos cabañas y quiero ver el channel manager.',
        ]))
            ->assertCreated()
            ->assertJsonPath('message', 'Recibimos tu pedido. Te contactamos para coordinar la demo.');
    }

    public function test_signup_lead_requires_accommodation_name_and_phone(): void
    {
        $this->postJson('/api/v1/leads', $this->payload(['accommodation_name' => '', 'phone' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accommodation_name', 'phone']);
    }

    public function test_honeypot_rejects_bots(): void
    {
        $this->postJson('/api/v1/leads', $this->payload(['website' => 'http://spam.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['website']);

        $this->assertFalse(Lead::where('email', 'laura@hostal.test')->exists());
    }

    public function test_without_notify_email_lead_is_stored_silently(): void
    {
        config(['api.leads_notify_email' => '']);

        $this->postJson('/api/v1/leads', $this->payload())->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_with_turnstile_configured_a_valid_token_is_required(): void
    {
        config(['services.turnstile.secret_key' => 'secreto-test']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->postJson('/api/v1/leads', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cf-turnstile-response']);
        Http::assertNothingSent();

        $this->postJson('/api/v1/leads', $this->payload(['cf-turnstile-response' => 'token-ok']))->assertCreated();

        Http::assertSent(fn (HttpRequest $request) => $request['secret'] === 'secreto-test'
            && $request['response'] === 'token-ok');
        $this->assertTrue(Lead::where('email', 'laura@hostal.test')->exists());
    }

    public function test_rejected_turnstile_token_does_not_store_the_lead(): void
    {
        config(['services.turnstile.secret_key' => 'secreto-test']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
        ]);

        $this->postJson('/api/v1/leads', $this->payload(['cf-turnstile-response' => 'token-trucho']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cf-turnstile-response']);

        $this->assertFalse(Lead::where('email', 'laura@hostal.test')->exists());
        Mail::assertNothingQueued();
    }
}
