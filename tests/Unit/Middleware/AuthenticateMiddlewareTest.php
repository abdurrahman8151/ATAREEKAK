<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticateMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_returns_401(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_unauthenticated_api_request_is_not_a_redirect(): void
    {
        $response = $this->getJson('/api/user');
        $this->assertNotEquals(302, $response->status());
    }

    public function test_authenticated_api_request_passes_through(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->withToken($token)->getJson('/api/user')->assertStatus(200);
    }

    public function test_middleware_class_can_be_resolved_from_container(): void
    {
        $middleware = $this->app->make(Authenticate::class);
        $this->assertInstanceOf(Authenticate::class, $middleware);
    }

    public function test_api_request_with_invalid_token_returns_401(): void
    {
        $this->withToken('invalid.token.here')
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_api_request_without_bearer_prefix_returns_401(): void
    {
        $this->getJson('/api/user', ['Authorization' => 'Basic abc123'])
            ->assertStatus(401);
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }
}
