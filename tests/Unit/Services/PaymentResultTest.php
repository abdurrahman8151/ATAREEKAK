<?php

namespace Tests\Unit\Services;

use App\Modules\Money\Domain\Strategies\PaymentResult;
use App\Modules\Money\Domain\Strategies\PaymentStrategy; // loading this file also defines PaymentResult & RefundResult
use PHPUnit\Framework\TestCase;

/**
 * FIX: PaymentResult is defined INSIDE PaymentStrategy.php not its own file.
 * Adding `use PaymentStrategy` forces PHP to load the file which registers PaymentResult in memory.
 */
class PaymentResultTest extends TestCase
{
    public function test_success_result(): void
    {
        $result = PaymentResult::success('Done');
        $this->assertTrue($result->success);
        $this->assertEquals('Done', $result->message);
    }

    public function test_failure_result(): void
    {
        $result = PaymentResult::failure('Error');
        $this->assertFalse($result->success);
        $this->assertEquals('Error', $result->message);
    }

    public function test_default_success_message(): void
    {
        $result = PaymentResult::success();
        $this->assertEquals('Payment processed successfully', $result->message);
    }
}
