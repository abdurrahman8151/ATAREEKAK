<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshots\SnapshotNormaliser;

/**
 * The normaliser's contract, pinned as values rather than as prose.
 *
 * Every rule here is one that a snapshot depends on: if "only a non-null scalar is masked" or "the
 * same id gets the same alias" ever changes silently, the whole corpus starts pinning something the
 * app never sent, and the failure shows up as a hundred unrelated-looking diffs. This class fails
 * first, in one place, with no database involved.
 */
class SnapshotNormaliserTest extends TestCase
{
    private function normalise(mixed $value): mixed
    {
        return SnapshotNormaliser::normalise($value);
    }

    /** @return array<string, mixed> */
    private function normaliseObject(array $value): array
    {
        return (array) json_decode(
            (string) json_encode($this->normalise(json_decode((string) json_encode($value)))),
            true
        );
    }

    public function test_only_a_non_null_scalar_under_a_masked_key_is_replaced(): void
    {
        $out = $this->normaliseObject([
            'id' => 7,
            'user_id' => null,
            'driver_id' => '12',
            'created_at' => null,
            'updated_at' => false,
            'expires_at' => true,
            'token' => null,
        ]);

        $this->assertSame('<id:2>', $out['id'], 'a real id is masked with its alias');
        $this->assertNull($out['user_id'], 'null keeps its shape - it is not the string "<id>"');
        $this->assertSame('<id:1>', $out['driver_id'], 'a numeric string id is masked too (sorted keys: driver_id is seen first)');
        $this->assertNull($out['created_at'], 'a null timestamp stays null');
        $this->assertFalse($out['updated_at'], 'a boolean under a timestamp key stays a boolean');
        $this->assertTrue($out['expires_at'], 'a boolean under a timestamp key stays a boolean');
        $this->assertNull($out['token'], 'a null token stays null');
    }

    public function test_arrays_and_objects_under_a_masked_key_keep_their_shape(): void
    {
        $out = $this->normaliseObject([
            'ids' => [4, 7],
            'booking_ids' => [['id' => 9]],
            'metadata' => ['source' => 'snapshot'],
        ]);

        $this->assertSame(['<id:2>', '<id:3>'], $out['ids'], 'a list of ids stays a list of aliases');
        $this->assertSame(
            [['id' => '<id:1>']],
            $out['booking_ids'],
            'a list of objects stays a list of objects; the inner key keeps its own rule'
        );
        $this->assertSame(['source' => 'snapshot'], $out['metadata'], 'an unmasked object is untouched');
    }

    public function test_the_same_id_gets_the_same_alias_and_different_ids_get_different_ones(): void
    {
        $out = $this->normaliseObject([
            'id' => 5,
            'driver_id' => 5,
            'passenger_id' => 9,
            'rider_id' => 5,
        ]);

        $this->assertSame('<id:1>', $out['id']);
        $this->assertSame('<id:1>', $out['driver_id'], 'the same id in another field carries the same alias');
        $this->assertSame('<id:2>', $out['passenger_id']);
        $this->assertSame('<id:1>', $out['rider_id']);
    }

    public function test_aliases_are_assigned_in_a_canonical_order_so_key_order_does_not_move_them(): void
    {
        $a = $this->normaliseObject(['zebra_id' => 11, 'alpha_id' => 22]);
        $b = $this->normaliseObject(['alpha_id' => 22, 'zebra_id' => 11]);

        $this->assertSame($a, $b, 'the response key order must not change which alias a value gets');
        $this->assertSame('<id:1>', $a['alpha_id'], 'assignment follows sorted keys, not insertion order');
        $this->assertSame('<id:2>', $a['zebra_id']);
    }

    public function test_emails_get_their_own_alias_family(): void
    {
        $out = $this->normaliseObject([
            'email' => 'a@example.test',
            'contact_email' => 'a@example.test',
            'other' => 'b@example.test',
            'note' => 'write to a@example.test',
        ]);

        $this->assertSame('<email:1>', $out['email']);
        $this->assertSame('<email:1>', $out['contact_email']);
        $this->assertSame('<email:2>', $out['other']);
        $this->assertSame(
            'write to a@example.test',
            $out['note'],
            'only a WHOLE value is masked as an e-mail; a sentence keeps its text, or the snapshot '
            .'would lose the words around it'
        );
    }

    public function test_ids_inside_a_url_path_share_the_body_alias(): void
    {
        $out = $this->normaliseObject([
            'id' => 33,
            'first_page_url' => 'https://api.example.test/api/admin/wallet/33/transactions?page=1',
        ]);

        $this->assertSame('<id:1>', $out['id']);
        $this->assertSame(
            'https://api.example.test/api/admin/wallet/<id:1>/transactions?page=1',
            $out['first_page_url'],
            'the paginator must be pinned to the SAME wallet the body describes'
        );
    }

    public function test_timestamps_tokens_files_and_redactions_keep_their_existing_rules(): void
    {
        $out = $this->normaliseObject([
            'created_at' => '2026-06-15T12:00:00+03:00',
            'departure_time_human' => '15 Jun 2026, 12:00',
            'access_token' => 'eyJhbGciOiJIUzI1NiJ9.abc123',
            'password' => 'hunter2',
            'stored' => 'profiles/profile_photo/AbCdEfGhIjKlMnOpQrSt.jpg',
            'mongo_like' => '9f8e7d6c-5b4a-3210-9f8e-7d6c5b4a3210',
        ]);

        $this->assertSame('<timestamp>', $out['created_at']);
        $this->assertSame('<timestamp>', $out['departure_time_human']);
        $this->assertSame('<token>', $out['access_token']);
        $this->assertSame('<redacted>', $out['password']);
        $this->assertSame('<file>', $out['stored']);
        $this->assertSame(
            '<token>',
            $out['mongo_like'],
            'a dashed uuid is 32+ alnum-with-a-digit characters, so the token rule claims it first; both '
            .'rules mask, so this is a label, not a leak (the <uuid> rule only fires for a dash-free form)'
        );
    }

    public function test_a_timestamp_embedded_in_a_sentence_is_replaced_in_place(): void
    {
        $out = $this->normaliseObject([
            'notes' => "[ESCALATED by Snapshot Staff at 2026-06-15 12:00:00]\nThe rider was late.",
        ]);

        $this->assertSame(
            "[ESCALATED by Snapshot Staff at <timestamp>]\nThe rider was late.",
            $out['notes'],
            'the sentence around the clock instant stays compared byte-for-byte'
        );
    }

    public function test_amounts_counts_and_statuses_are_never_touched(): void
    {
        $out = $this->normaliseObject([
            'total' => 1000.5,
            'count' => 3,
            'status' => 'completed',
            'is_verified' => true,
            'note' => 'دفع نقدي',
        ]);

        $this->assertSame(1000.5, $out['total']);
        $this->assertSame(3, $out['count']);
        $this->assertSame('completed', $out['status']);
        $this->assertTrue($out['is_verified']);
        $this->assertSame('دفع نقدي', $out['note']);
    }
}
