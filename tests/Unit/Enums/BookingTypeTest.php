<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use PHPUnit\Framework\TestCase;

class BookingTypeTest extends TestCase
{
    public function test_direct_does_not_require_approval(): void
    {
        $this->assertFalse(BookingType::DIRECT->requiresApproval());
    }

    public function test_request_requires_approval(): void
    {
        $this->assertTrue(BookingType::REQUEST->requiresApproval());
    }

    public function test_direct_processes_payment_immediately(): void
    {
        $this->assertTrue(BookingType::DIRECT->processPaymentImmediately());
    }

    public function test_request_defers_payment(): void
    {
        $this->assertFalse(BookingType::REQUEST->processPaymentImmediately());
    }

    public function test_direct_initial_status_is_confirmed(): void
    {
        $this->assertEquals(BookingStatus::CONFIRMED, BookingType::DIRECT->initialBookingStatus());
    }

    public function test_request_initial_status_is_pending(): void
    {
        $this->assertEquals(BookingStatus::PENDING, BookingType::REQUEST->initialBookingStatus());
    }
}
