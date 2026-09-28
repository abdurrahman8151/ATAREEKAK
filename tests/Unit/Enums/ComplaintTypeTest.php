<?php

namespace Tests\Unit\Enums;

use App\Enums\ComplaintType;
use PHPUnit\Framework\TestCase;

class ComplaintTypeTest extends TestCase
{
    // ─── label() ──────────────────────────────────────────────────────────────

    public function test_trip_safety_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::TRIP_SAFETY->label());
    }

    public function test_driver_behavior_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::DRIVER_BEHAVIOR->label());
    }

    public function test_passenger_behavior_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::PASSENGER_BEHAVIOR->label());
    }

    public function test_ride_cancellation_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::RIDE_CANCELLATION->label());
    }

    public function test_financial_issue_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::FINANCIAL_ISSUE->label());
    }

    public function test_account_issue_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::ACCOUNT_ISSUE->label());
    }

    public function test_technical_issue_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::TECHNICAL_ISSUE->label());
    }

    public function test_other_has_label(): void
    {
        $this->assertNotEmpty(ComplaintType::OTHER->label());
    }

    // ─── Values ───────────────────────────────────────────────────────────────

    public function test_enum_values_are_correct_strings(): void
    {
        $this->assertEquals('trip_safety', ComplaintType::TRIP_SAFETY->value);
        $this->assertEquals('driver_behavior', ComplaintType::DRIVER_BEHAVIOR->value);
        $this->assertEquals('passenger_behavior', ComplaintType::PASSENGER_BEHAVIOR->value);
        $this->assertEquals('ride_cancellation', ComplaintType::RIDE_CANCELLATION->value);
        $this->assertEquals('financial_issue', ComplaintType::FINANCIAL_ISSUE->value);
        $this->assertEquals('account_issue', ComplaintType::ACCOUNT_ISSUE->value);
        $this->assertEquals('technical_issue', ComplaintType::TECHNICAL_ISSUE->value);
        $this->assertEquals('other', ComplaintType::OTHER->value);
    }

    public function test_from_string_resolves_correctly(): void
    {
        $this->assertEquals(ComplaintType::TRIP_SAFETY, ComplaintType::from('trip_safety'));
        $this->assertEquals(ComplaintType::OTHER, ComplaintType::from('other'));
        $this->assertEquals(ComplaintType::FINANCIAL_ISSUE, ComplaintType::from('financial_issue'));
        $this->assertEquals(ComplaintType::DRIVER_BEHAVIOR, ComplaintType::from('driver_behavior'));
    }

    public function test_all_cases_have_non_empty_labels(): void
    {
        foreach (ComplaintType::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Label for {$case->name} should not be empty");
        }
    }

    public function test_all_labels_are_strings(): void
    {
        foreach (ComplaintType::cases() as $case) {
            $this->assertIsString($case->label());
        }
    }

    public function test_eight_complaint_types_exist(): void
    {
        $this->assertCount(8, ComplaintType::cases());
    }
}
