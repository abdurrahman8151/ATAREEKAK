<?php

namespace Tests\Unit\Enums;

use App\Enums\ComplaintStatus;
use PHPUnit\Framework\TestCase;

class ComplaintStatusTest extends TestCase
{
    // ─── label() ──────────────────────────────────────────────────────────────

    public function test_pending_label(): void
    {
        $this->assertEquals('Pending', ComplaintStatus::PENDING->label());
    }

    public function test_in_review_label(): void
    {
        $this->assertEquals('In Review', ComplaintStatus::IN_REVIEW->label());
    }

    public function test_escalated_label(): void
    {
        $this->assertEquals('Escalated', ComplaintStatus::ESCALATED->label());
    }

    public function test_resolved_label(): void
    {
        $this->assertEquals('Resolved', ComplaintStatus::RESOLVED->label());
    }

    public function test_closed_label(): void
    {
        $this->assertEquals('Closed', ComplaintStatus::CLOSED->label());
    }

    // ─── color() ──────────────────────────────────────────────────────────────

    public function test_pending_color_is_yellow(): void
    {
        $this->assertEquals('yellow', ComplaintStatus::PENDING->color());
    }

    public function test_in_review_color_is_blue(): void
    {
        $this->assertEquals('blue', ComplaintStatus::IN_REVIEW->color());
    }

    public function test_escalated_color_is_orange(): void
    {
        $this->assertEquals('orange', ComplaintStatus::ESCALATED->color());
    }

    public function test_resolved_color_is_green(): void
    {
        $this->assertEquals('green', ComplaintStatus::RESOLVED->color());
    }

    public function test_closed_color_is_gray(): void
    {
        $this->assertEquals('gray', ComplaintStatus::CLOSED->color());
    }

    // ─── isOpen() ─────────────────────────────────────────────────────────────

    public function test_pending_is_open(): void
    {
        $this->assertTrue(ComplaintStatus::PENDING->isOpen());
    }

    public function test_in_review_is_open(): void
    {
        $this->assertTrue(ComplaintStatus::IN_REVIEW->isOpen());
    }

    public function test_escalated_is_open(): void
    {
        $this->assertTrue(ComplaintStatus::ESCALATED->isOpen());
    }

    public function test_resolved_is_not_open(): void
    {
        $this->assertFalse(ComplaintStatus::RESOLVED->isOpen());
    }

    public function test_closed_is_not_open(): void
    {
        $this->assertFalse(ComplaintStatus::CLOSED->isOpen());
    }

    // ─── isAgentActionable() ──────────────────────────────────────────────────

    public function test_pending_is_agent_actionable(): void
    {
        $this->assertTrue(ComplaintStatus::PENDING->isAgentActionable());
    }

    public function test_in_review_is_agent_actionable(): void
    {
        $this->assertTrue(ComplaintStatus::IN_REVIEW->isAgentActionable());
    }

    public function test_escalated_is_not_agent_actionable(): void
    {
        // Escalated complaints belong to admin, not agents
        $this->assertFalse(ComplaintStatus::ESCALATED->isAgentActionable());
    }

    public function test_resolved_is_not_agent_actionable(): void
    {
        $this->assertFalse(ComplaintStatus::RESOLVED->isAgentActionable());
    }

    public function test_closed_is_not_agent_actionable(): void
    {
        $this->assertFalse(ComplaintStatus::CLOSED->isAgentActionable());
    }

    // ─── Values ───────────────────────────────────────────────────────────────

    public function test_enum_values_are_correct_strings(): void
    {
        $this->assertEquals('pending', ComplaintStatus::PENDING->value);
        $this->assertEquals('in_review', ComplaintStatus::IN_REVIEW->value);
        $this->assertEquals('escalated', ComplaintStatus::ESCALATED->value);
        $this->assertEquals('resolved', ComplaintStatus::RESOLVED->value);
        $this->assertEquals('closed', ComplaintStatus::CLOSED->value);
    }

    public function test_from_string_resolves_correctly(): void
    {
        $this->assertEquals(ComplaintStatus::PENDING, ComplaintStatus::from('pending'));
        $this->assertEquals(ComplaintStatus::IN_REVIEW, ComplaintStatus::from('in_review'));
        $this->assertEquals(ComplaintStatus::ESCALATED, ComplaintStatus::from('escalated'));
        $this->assertEquals(ComplaintStatus::RESOLVED, ComplaintStatus::from('resolved'));
        $this->assertEquals(ComplaintStatus::CLOSED, ComplaintStatus::from('closed'));
    }

    public function test_all_cases_have_non_empty_labels(): void
    {
        foreach (ComplaintStatus::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Label for {$case->name} should not be empty");
        }
    }

    public function test_all_cases_have_non_empty_colors(): void
    {
        foreach (ComplaintStatus::cases() as $case) {
            $this->assertNotEmpty($case->color(), "Color for {$case->name} should not be empty");
        }
    }
}
