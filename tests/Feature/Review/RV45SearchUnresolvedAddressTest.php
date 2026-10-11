<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RV-45 + owner ruling (search 422): an address that does not resolve is a curated refusal the user
 * can correct, so GET /api/rides/search returns 422 with the real sentence, not a generic 500.
 * A genuine geocoding fault must still return the generic 500 and must not leak.
 *
 * GeocodingService is final, so the real service runs with Nominatim faked (Http::fake), which is
 * the same approach RV50UnresolvedAddressTest uses.
 */
class RV45SearchUnresolvedAddressTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }

    public function test_unresolved_source_address_is_a_curated_422(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->withToken($this->token)->getJson('/api/rides/search?'.http_build_query([
            'source_address' => 'xyzzy_nowhere',
            'destination_address' => 'Aleppo, Syria',
            'departure_date' => now()->addDay()->toDateString(),
            'seats_required' => 1,
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertStringContainsString('source address', (string) $response->json('message'));
        $this->assertStringContainsString('xyzzy_nowhere', (string) $response->json('message'));
    }

    public function test_genuine_geocoding_fault_stays_a_generic_500_and_does_not_leak(): void
    {
        // Nominatim is down: a real fault, not a user refusal.
        Http::fake(fn () => Http::response('internal upstream detail XYZ', 500));

        $response = $this->withToken($this->token)->getJson('/api/rides/search?'.http_build_query([
            'source_address' => 'Damascus, Syria',
            'destination_address' => 'Aleppo, Syria',
            'departure_date' => now()->addDay()->toDateString(),
            'seats_required' => 1,
        ]));

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Search failed.');
        $this->assertStringNotContainsString('upstream detail XYZ', $response->getContent());
    }
}
