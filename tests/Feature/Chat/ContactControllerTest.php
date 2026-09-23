<?php

namespace Tests\Feature\Chat;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactControllerTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user  = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->getToken($this->user);
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_contact_requires_authentication(): void
    {
        $this->postJson('/api/contact')->assertStatus(401);
    }

    // ─── No agents available ──────────────────────────────────────────────────

    public function test_returns_503_when_no_support_agents_exist(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/contact')
            ->assertStatus(503);
    }

    public function test_returns_503_when_agent_employee_exists_but_has_no_user_account(): void
    {
        // Employee row exists but no matching User row — controller returns 503
        Employee::create([
            'username'      => 'orphaned_agent',
            'email'         => 'orphan@support.test',
            'password'      => bcrypt('password123'),
            'first_name'    => 'Orphaned',
            'last_name'     => 'Agent',
            'role'          => StaffRole::SUPPORT_AGENT->value,
            'is_active'     => true,
            'token_version' => 0,
        ]);
        // Deliberately NOT creating a matching User row

        $this->withToken($this->token)
            ->postJson('/api/contact')
            ->assertStatus(503);
    }

    public function test_returns_503_when_all_agents_are_inactive(): void
    {
        $this->seedAgent(active: false);

        $this->withToken($this->token)
            ->postJson('/api/contact')
            ->assertStatus(503);
    }

    // ─── With active agent ────────────────────────────────────────────────────

    public function test_creates_conversation_and_returns_conversation_id(): void
    {
        $this->seedAgent();

        $response = $this->withToken($this->token)->postJson('/api/contact');

        $this->assertContains($response->status(), [200, 201]);
        $response->assertJsonStructure(['status', 'conversation_id', 'message', 'agent']);
    }

    public function test_response_includes_agent_name(): void
    {
        $this->seedAgent(firstName: 'Samer', lastName: 'Khalil');

        $response = $this->withToken($this->token)->postJson('/api/contact');

        $this->assertContains($response->status(), [200, 201]);
        $this->assertNotNull($response->json('agent.name'));
    }

    public function test_second_call_returns_same_conversation_id(): void
    {
        $this->seedAgent();

        $r1 = $this->withToken($this->token)->postJson('/api/contact');
        $r2 = $this->withToken($this->token)->postJson('/api/contact');

        $this->assertContains($r1->status(), [200, 201]);
        $this->assertContains($r2->status(), [200, 201]);
        $this->assertEquals($r1->json('conversation_id'), $r2->json('conversation_id'));
    }

    public function test_different_users_receive_separate_conversations(): void
    {
        $this->seedAgent();

        $other      = User::factory()->create(['password' => bcrypt('password123')]);
        $otherToken = $this->getToken($other);

        $r1 = $this->withToken($this->token)->postJson('/api/contact');
        $r2 = $this->withToken($otherToken)->postJson('/api/contact');

        $this->assertContains($r1->status(), [200, 201]);
        $this->assertContains($r2->status(), [200, 201]);
        $this->assertNotEquals($r1->json('conversation_id'), $r2->json('conversation_id'));
    }

    public function test_first_contact_returns_201_created(): void
    {
        $this->seedAgent();

        $this->withToken($this->token)
            ->postJson('/api/contact')
            ->assertStatus(201);
    }

    public function test_repeat_contact_returns_200_existing(): void
    {
        $this->seedAgent();

        // First call creates it
        $this->withToken($this->token)->postJson('/api/contact');

        // Second call finds the existing conversation
        $this->withToken($this->token)
            ->postJson('/api/contact')
            ->assertStatus(200);
    }

    // ─── Multiple agents (load balancing must not fork the thread) ────────────

    public function test_second_call_returns_same_conversation_with_multiple_agents(): void
    {
        // Two agents: after the first chat is created, agent #1 has one support
        // conversation and agent #2 has none — so the least-loaded pick flips to
        // agent #2 on the second call. The user must still get their own thread.
        $this->seedAgent(username: 'agent_one', email: 'one@support.test');
        $this->seedAgent(username: 'agent_two', email: 'two@support.test');

        $r1 = $this->withToken($this->token)->postJson('/api/contact');
        $r2 = $this->withToken($this->token)->postJson('/api/contact');

        $r1->assertStatus(201);
        $r2->assertStatus(200);
        $this->assertEquals($r1->json('conversation_id'), $r2->json('conversation_id'));
        $this->assertEquals($r1->json('agent.name'), $r2->json('agent.name'));

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_repeated_calls_never_accumulate_conversations(): void
    {
        $this->seedAgent(username: 'agent_one', email: 'one@support.test');
        $this->seedAgent(username: 'agent_two', email: 'two@support.test');
        $this->seedAgent(username: 'agent_three', email: 'three@support.test');

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->withToken($this->token)->postJson('/api/contact')->json('conversation_id');
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertDatabaseCount('conversations', 1);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function seedAgent(
        bool   $active    = true,
        string $firstName = 'Support',
        string $lastName  = 'Agent',
        string $username  = 'support_agent',
        string $email     = 'agent@support.test',
    ): User {
        $agentUser = User::factory()->create([
            'email'    => $email,
            'password' => bcrypt('password123'),
        ]);

        Employee::create([
            'username'      => $username,
            'email'         => $email,
            'password'      => bcrypt('password123'),
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'role'          => StaffRole::SUPPORT_AGENT->value,
            'is_active'     => $active,
            'token_version' => 0,
        ]);

        return $agentUser;
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
