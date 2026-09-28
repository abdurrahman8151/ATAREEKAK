<?php

namespace Tests\Unit\Models;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationParticipantTest extends TestCase
{
    use RefreshDatabase;

    // ── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_contains_conversation_id(): void
    {
        $this->assertContains('conversation_id', (new ConversationParticipant)->getFillable());
    }

    public function test_fillable_contains_user_id(): void
    {
        $this->assertContains('user_id', (new ConversationParticipant)->getFillable());
    }

    public function test_fillable_contains_role(): void
    {
        $this->assertContains('role', (new ConversationParticipant)->getFillable());
    }

    public function test_fillable_contains_joined_at(): void
    {
        $this->assertContains('joined_at', (new ConversationParticipant)->getFillable());
    }

    public function test_fillable_contains_last_read_at(): void
    {
        $this->assertContains('last_read_at', (new ConversationParticipant)->getFillable());
    }

    // ── Casts ─────────────────────────────────────────────────────────────────

    public function test_joined_at_is_cast_to_datetime(): void
    {
        $casts = (new ConversationParticipant)->getCasts();
        $this->assertArrayHasKey('joined_at', $casts);
        $this->assertEquals('datetime', $casts['joined_at']);
    }

    public function test_last_read_at_is_cast_to_datetime(): void
    {
        $casts = (new ConversationParticipant)->getCasts();
        $this->assertArrayHasKey('last_read_at', $casts);
        $this->assertEquals('datetime', $casts['last_read_at']);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function test_has_conversation_relationship_method(): void
    {
        $this->assertTrue(method_exists(ConversationParticipant::class, 'conversation'));
    }

    public function test_has_user_relationship_method(): void
    {
        $this->assertTrue(method_exists(ConversationParticipant::class, 'user'));
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function test_participant_can_be_created_in_database(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create(['type' => 'private']);

        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_relationship_returns_correct_user(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create(['type' => 'private']);

        $participant = ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'joined_at' => now(),
        ]);

        $this->assertEquals($user->id, $participant->user->id);
    }

    public function test_conversation_relationship_returns_correct_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create(['type' => 'private']);

        $participant = ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'joined_at' => now(),
        ]);

        $this->assertEquals($conversation->id, $participant->conversation->id);
    }

    public function test_multiple_participants_can_join_same_conversation(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $conversation = Conversation::create(['type' => 'private']);

        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id, 'joined_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id, 'joined_at' => now()]);

        $count = ConversationParticipant::where('conversation_id', $conversation->id)->count();
        $this->assertEquals(2, $count);
    }
}
