<?php

namespace Tests\Unit\Services;

use App\Domain\Payment\Strategies\RefundResult;
use PHPUnit\Framework\TestCase;

/**
 * RefundResultTest — Unit tests for the RefundResult value object.
 *
 * LOCATION: tests/Unit/Services/RefundResultTest.php
 *
 * COVERS:
 * - RefundResult::success()
 * - RefundResult::failure()
 * - Default messages
 * - transactionIds optional field
 * - Public readonly properties
 */
class RefundResultTest extends TestCase
{
    // ─── success() ─────────────────────────────────────────────────────────────

    public function test_success_sets_success_true(): void
    {
        $result = RefundResult::success('Refund complete');
        $this->assertTrue($result->success);
    }

    public function test_success_sets_custom_message(): void
    {
        $result = RefundResult::success('Full refund issued');
        $this->assertEquals('Full refund issued', $result->message);
    }

    public function test_success_uses_default_message_when_not_provided(): void
    {
        $result = RefundResult::success();
        $this->assertEquals('Refund processed successfully', $result->message);
    }

    public function test_success_with_transaction_ids(): void
    {
        $ids = ['TX_001', 'TX_002'];
        $result = RefundResult::success('Done', $ids);
        $this->assertEquals($ids, $result->transactionIds);
    }

    public function test_success_transaction_ids_default_to_null(): void
    {
        $result = RefundResult::success();
        $this->assertNull($result->transactionIds);
    }

    // ─── failure() ─────────────────────────────────────────────────────────────

    public function test_failure_sets_success_false(): void
    {
        $result = RefundResult::failure('Wallet not found');
        $this->assertFalse($result->success);
    }

    public function test_failure_sets_error_message(): void
    {
        $result = RefundResult::failure('Insufficient admin balance');
        $this->assertEquals('Insufficient admin balance', $result->message);
    }

    public function test_failure_transaction_ids_are_null(): void
    {
        $result = RefundResult::failure('Error');
        $this->assertNull($result->transactionIds);
    }

    // ─── Properties are readonly ────────────────────────────────────────────────

    public function test_success_result_is_instance_of_refund_result(): void
    {
        $this->assertInstanceOf(RefundResult::class, RefundResult::success());
    }

    public function test_failure_result_is_instance_of_refund_result(): void
    {
        $this->assertInstanceOf(RefundResult::class, RefundResult::failure('Error'));
    }

    public function test_success_and_failure_produce_different_results(): void
    {
        $success = RefundResult::success('OK');
        $failure = RefundResult::failure('Not OK');

        $this->assertTrue($success->success);
        $this->assertFalse($failure->success);
    }

    // ─── Edge cases ─────────────────────────────────────────────────────────────

    public function test_success_with_empty_transaction_ids_array(): void
    {
        $result = RefundResult::success('Done', []);
        $this->assertIsArray($result->transactionIds);
        $this->assertEmpty($result->transactionIds);
    }

    public function test_failure_message_preserves_special_characters(): void
    {
        $msg = 'Wallet #123 insufficient: requires 1,000 SYP';
        $result = RefundResult::failure($msg);
        $this->assertEquals($msg, $result->message);
    }

    public function test_success_message_preserves_arabic_text(): void
    {
        $msg = 'تم استرداد المبلغ بنجاح';
        $result = RefundResult::success($msg);
        $this->assertEquals($msg, $result->message);
    }
}
