<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Communication\Models\Conversation;
use App\Modules\Communication\Models\ConversationParticipant;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Services\ChatMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 2 C2 characterization test for the chat page's sender photos.
 *
 * WHY IT EXISTS. The previous attempt to remove `Communication -> Profile` turned the bulk
 * `sender.profile` eager load into a per-message `Profile::where(...)` read - an N+1 the query budgets
 * caught - and the attempt to fix THAT dropped the `sender` eager load, so `$message->sender` lazy-loaded
 * under the armed guard and `chat/messages-index` answered 500. Both failure modes are visible here as a
 * ONE-query difference:
 *
 *   - message select        1
 *   - `sender` eager load   1   (must stay: `Message` guards lazy loading)
 *   - photo batch           1   (ONE query for every sender on the page, whatever the page size)
 *
 * It passes on the pre-change code too (`sender.profile` was also one query), which is what makes it a
 * characterization test rather than an assertion written to match the new code.
 */
final class ChatMessagePhotoBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_page_resolves_every_sender_photo_in_one_query(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $conversation = Conversation::create(['type' => 'private']);

        foreach ([$alice, $bob] as $participant) {
            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $participant->id,
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }

        foreach ([$alice, $alice, $bob] as $index => $sender) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'type' => 'text',
                'content' => "message {$index}",
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $rows = app(ChatMessageHandler::class)->getFormattedMessages($conversation->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $rows, 'the page returns every message');
        $this->assertSame(
            3,
            count($queries),
            'expected messages + senders + ONE photo batch, got: '.json_encode(array_column($queries, 'query'))
        );

        foreach ($rows as $row) {
            $this->assertArrayHasKey('profile_photo', $row['sender']);
        }
    }
}
