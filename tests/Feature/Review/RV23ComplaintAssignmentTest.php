<?php

namespace Tests\Feature\Review;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\User;
use App\Services\Complaint\ComplaintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-23 (slice 2) — complaints distribute to the LEAST-LOADED agent, not to one.
 *
 * Root cause (verified in code): ComplaintService::submit picked an agent with
 * `->where('role','support_agent')->where('is_active',true)->first()` — no ordering.
 * `first()` returns the same first-matched row every time, so with one busy agent and
 * ten idle ones EVERY complaint landed on that busy agent: its backlog grows unbounded
 * (an SLA/availability bug) while the other agents never receive work. R1 prescribed the
 * ContactController least-loaded pattern; complaints reach employees directly via
 * assigned_to, so the load subquery needs no email join.
 *
 * The pins below prove redistribution (the busy agent is skipped) AND that eligibility is
 * unchanged (still only active support_agent rows) AND the deterministic id tie-break.
 */
class RV23ComplaintAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function submitter(): ComplaintService
    {
        return app(ComplaintService::class);
    }

    private function agent(string $tag): Employee
    {
        return Employee::create([
            'username' => "sup_{$tag}_".uniqid(),
            'email' => "sup_{$tag}_".uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'Sup',
            'last_name' => ucfirst($tag),
            'role' => 'support_agent',
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    private function seedOpenFor(Employee $agent, int $n, User $reporter): void
    {
        for ($i = 0; $i < $n; $i++) {
            Complaint::create([
                'user_id' => $reporter->id,
                'assigned_to' => $agent->id,
                'title' => "open {$i}",
                'description' => 'd',
                'type' => ComplaintType::OTHER->value,
                'status' => ComplaintStatus::PENDING->value,
            ]);
        }
    }

    private function submitOnce(User $reporter): Complaint
    {
        return $this->submitter()->submit([
            'title' => 'new complaint',
            'description' => 'body',
            'type' => ComplaintType::OTHER->value,
        ], $reporter, []);
    }

    /** @test */
    public function a_busy_agent_is_skipped_for_an_idle_one(): void
    {
        $reporter = User::factory()->create();
        $busy = $this->agent('busy');     // created first -> lower id
        $idle = $this->agent('idle');

        // The head-of-queue bug: even with 5 open complaints, first() kept returning $busy.
        $this->seedOpenFor($busy, 5, $reporter);

        $assigned = $this->submitOnce($reporter)->assigned_to;

        $this->assertSame(
            $idle->id,
            (int) $assigned,
            'RV-23: a new complaint must go to the agent with fewer open cases, not the first row'
        );
    }

    /** @test */
    public function successive_submissions_balance_across_two_idle_agents(): void
    {
        $reporter = User::factory()->create();
        $a = $this->agent('a');
        $b = $this->agent('b');

        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $seen[] = (int) $this->submitOnce($reporter)->assigned_to;
            // Re-resolve agents fresh each loop so their counts advance in the subquery.
        }

        // Both agents get work — proves it is NOT pinned to one row (the pre-fix behaviour).
        $this->assertContains($a->id, $seen, 'agent A must receive some complaints');
        $this->assertContains($b->id, $seen, 'agent B must receive some complaints');
        $this->assertCount(
            2,
            array_unique($seen),
            '4 complaints across 2 idle agents must not all land on one agent'
        );
    }

    /** @test */
    public function resolved_and_closed_cases_do_not_count_as_load(): void
    {
        $reporter = User::factory()->create();
        $older = $this->agent('older');   // lower id, but carries 3 complaints
        $younger = $this->agent('younger');

        // Give the lower-id agent complaints that are all DONE — so its live load is 0.
        for ($i = 0; $i < 3; $i++) {
            Complaint::create([
                'user_id' => $reporter->id,
                'assigned_to' => $older->id,
                'title' => "done {$i}",
                'description' => 'd',
                'type' => ComplaintType::OTHER->value,
                'status' => $i === 0 ? ComplaintStatus::RESOLVED->value : ComplaintStatus::CLOSED->value,
            ]);
        }

        // Both have ZERO open cases -> tie-break by id -> the older (lower-id) wins.
        $assigned = (int) $this->submitOnce($reporter)->assigned_to;
        $this->assertSame(
            $older->id,
            $assigned,
            'resolved/closed must not inflate load; a real tie breaks deterministically to lower id'
        );
    }

    /** @test */
    public function a_deactivated_agent_is_never_chosen_even_if_idlest(): void
    {
        $reporter = User::factory()->create();
        $active = $this->agent('active');
        $sick = $this->agent('sick');

        // Pile open work on the active agent so the idle DEACTIVATED agent would win on load.
        $this->seedOpenFor($active, 6, $reporter);
        $sick->update(['is_active' => false]);

        $assigned = (int) $this->submitOnce($reporter)->assigned_to;

        $this->assertSame($active->id, $assigned,
            'RV-23: eligibility is unchanged — inactive agents stay excluded regardless of load');
        $this->assertNotSame($sick->id, $assigned,
            'a deactivated agent must never receive new work');
    }

    /** @test */
    public function with_no_agents_the_complaint_still_creates_unassigned(): void
    {
        // Behaviour preservation: $agent?->id stays null-safe (pre-existing null branch).
        $reporter = User::factory()->create();

        $complaint = $this->submitOnce($reporter);

        $this->assertNull($complaint->assigned_to, 'no agents -> unassigned, not an error');
        // status is cast to the ComplaintStatus enum on the model, so compare the enum.
        $this->assertSame(ComplaintStatus::PENDING, $complaint->status);
    }
}
