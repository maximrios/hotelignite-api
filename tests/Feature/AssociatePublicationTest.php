<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El gate de publicación: el client habilita qué asociados salen en su web
 * (`accommodation_client.verified_at`) y la API pública sólo devuelve esos.
 */
class AssociatePublicationTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cityId = (int) DB::table('cities')->value('id');
    }

    private function accommodation(bool $owned = true): Accommodation
    {
        $account = $owned ? Account::create(['name' => 'Cuenta '.uniqid()]) : null;

        return Accommodation::create([
            'account_id' => $account?->id,
            'city_id' => $this->cityId,
            'name' => 'Hotel '.uniqid(),
            'slug' => 'test-'.uniqid(),
            'enabled' => 1,
        ]);
    }

    private function actAs(Client $client): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Client '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]));
    }

    private function publish(array $ids, bool $published = true): array
    {
        return $this->postJson('/api/client-panel/v1/associates/publication', [
            'entity_type' => 'accommodation',
            'entity_ids' => $ids,
            'published' => $published,
        ])->assertOk()->json();
    }

    public function test_agency_publishes_any_owned_associate_but_not_a_draft(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        $owned = $this->accommodation();
        $draft = $this->accommodation(owned: false);
        $client->accommodations()->attach([$owned->id, $draft->id], ['status' => 'active']);

        $this->actAs($client);
        $result = $this->publish([$owned->id, $draft->id]);

        $this->assertSame([$owned->id], $result['updated']);
        $this->assertSame([['entity_id' => $draft->id, 'reason' => 'no_owner']], $result['skipped']);
        $this->assertNotNull($client->accommodations()->find($owned->id)->pivot->verified_at);
    }

    public function test_chamber_only_publishes_members_who_agreed(): void
    {
        $client = Client::create(['name' => 'Cámara', 'type' => Client::TYPE_CHAMBER]);
        $member = $this->accommodation();
        $notMember = $this->accommodation();
        $client->accommodations()->attach($member->id, ['status' => 'active', 'agreed_at' => now()]);
        $client->accommodations()->attach($notMember->id, ['status' => 'active']);

        $this->actAs($client);
        $result = $this->publish([$member->id, $notMember->id]);

        $this->assertSame([$member->id], $result['updated']);
        $this->assertSame('not_agreed', $result['skipped'][0]['reason']);

        $row = collect($this->getJson('/api/client-panel/v1/associates')->json('associates.data'))
            ->firstWhere('entity_id', $notMember->id);
        $this->assertSame('not_agreed', $row['publish_blocker']);
    }

    public function test_cannot_publish_outside_own_padron(): void
    {
        $mine = Client::create(['name' => 'Mía', 'type' => Client::TYPE_AGENCY]);
        $other = Client::create(['name' => 'Otra', 'type' => Client::TYPE_AGENCY]);
        $hotel = $this->accommodation();
        $other->accommodations()->attach($hotel->id, ['status' => 'active']);

        $this->actAs($mine);
        $result = $this->publish([$hotel->id]);

        $this->assertSame([], $result['updated']);
        $this->assertSame('not_in_padron', $result['skipped'][0]['reason']);
        $this->assertNull($other->accommodations()->find($hotel->id)->pivot->verified_at);
    }

    public function test_public_api_only_returns_published_associates(): void
    {
        $client = Client::create(['name' => 'Agencia web', 'type' => Client::TYPE_AGENCY, 'active' => true]);
        $published = $this->accommodation();
        $hidden = $this->accommodation();
        $client->accommodations()->attach($published->id, ['status' => 'active', 'verified_at' => now()]);
        $client->accommodations()->attach($hidden->id, ['status' => 'active']);
        $key = ClientApiKey::generateFor($client, 'test', ['catalog:read'])['plain'];

        $slugs = collect($this->getJson('/api/client/v1/accommodations?limit=100', ['Authorization' => "Bearer {$key}"])
            ->assertOk()->json('data'))->pluck('slug');

        $this->assertTrue($slugs->contains($published->slug));
        $this->assertFalse($slugs->contains($hidden->slug));

        $this->getJson("/api/client/v1/accommodations/{$hidden->slug}", ['Authorization' => "Bearer {$key}"])->assertNotFound();
    }

    public function test_unpublish_takes_it_off_the_web(): void
    {
        $client = Client::create(['name' => 'Agencia', 'type' => Client::TYPE_AGENCY]);
        $hotel = $this->accommodation();
        $client->accommodations()->attach($hotel->id, ['status' => 'active', 'verified_at' => now()]);

        $this->actAs($client);
        $this->publish([$hotel->id], published: false);

        $this->assertNull($client->accommodations()->find($hotel->id)->pivot->verified_at);
    }
}
