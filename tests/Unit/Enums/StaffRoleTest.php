<?php

namespace Tests\Unit\Enums;

use App\Enums\StaffRole;
use PHPUnit\Framework\TestCase;

class StaffRoleTest extends TestCase
{
    // ─── label() ──────────────────────────────────────────────────────────────────

    public function test_system_admin_label(): void
    {
        $this->assertEquals('System Administrator', StaffRole::SYSTEM_ADMIN->label());
    }

    public function test_admin_label(): void
    {
        $this->assertEquals('Administrator', StaffRole::ADMIN->label());
    }

    public function test_support_agent_label(): void
    {
        $this->assertEquals('Support Agent', StaffRole::SUPPORT_AGENT->label());
    }

    // ─── level() ──────────────────────────────────────────────────────────────────

    public function test_system_admin_has_level_three(): void
    {
        $this->assertEquals(3, StaffRole::SYSTEM_ADMIN->level());
    }

    public function test_admin_has_level_two(): void
    {
        $this->assertEquals(2, StaffRole::ADMIN->level());
    }

    public function test_support_agent_has_level_one(): void
    {
        $this->assertEquals(1, StaffRole::SUPPORT_AGENT->level());
    }

    public function test_levels_are_strictly_ordered(): void
    {
        $this->assertGreaterThan(StaffRole::ADMIN->level(), StaffRole::SYSTEM_ADMIN->level());
        $this->assertGreaterThan(StaffRole::SUPPORT_AGENT->level(), StaffRole::ADMIN->level());
    }

    // ─── canManage() ──────────────────────────────────────────────────────────────

    public function test_system_admin_can_manage_admin(): void
    {
        $this->assertTrue(StaffRole::SYSTEM_ADMIN->canManage(StaffRole::ADMIN));
    }

    public function test_system_admin_can_manage_support_agent(): void
    {
        $this->assertTrue(StaffRole::SYSTEM_ADMIN->canManage(StaffRole::SUPPORT_AGENT));
    }

    public function test_system_admin_cannot_manage_itself(): void
    {
        $this->assertFalse(StaffRole::SYSTEM_ADMIN->canManage(StaffRole::SYSTEM_ADMIN));
    }

    public function test_admin_can_manage_support_agent(): void
    {
        $this->assertTrue(StaffRole::ADMIN->canManage(StaffRole::SUPPORT_AGENT));
    }

    public function test_admin_cannot_manage_system_admin(): void
    {
        $this->assertFalse(StaffRole::ADMIN->canManage(StaffRole::SYSTEM_ADMIN));
    }

    public function test_admin_cannot_manage_another_admin(): void
    {
        $this->assertFalse(StaffRole::ADMIN->canManage(StaffRole::ADMIN));
    }

    public function test_support_agent_cannot_manage_system_admin(): void
    {
        $this->assertFalse(StaffRole::SUPPORT_AGENT->canManage(StaffRole::SYSTEM_ADMIN));
    }

    public function test_support_agent_cannot_manage_admin(): void
    {
        $this->assertFalse(StaffRole::SUPPORT_AGENT->canManage(StaffRole::ADMIN));
    }

    public function test_support_agent_cannot_manage_another_support_agent(): void
    {
        $this->assertFalse(StaffRole::SUPPORT_AGENT->canManage(StaffRole::SUPPORT_AGENT));
    }

    // ─── creatableRoles() ─────────────────────────────────────────────────────────

    public function test_system_admin_can_create_two_roles(): void
    {
        $this->assertCount(2, StaffRole::SYSTEM_ADMIN->creatableRoles());
    }

    public function test_system_admin_creatable_roles_include_admin_and_support_agent(): void
    {
        $values = array_map(fn (StaffRole $r) => $r->value, StaffRole::SYSTEM_ADMIN->creatableRoles());
        $this->assertContains('admin', $values);
        $this->assertContains('support_agent', $values);
    }

    public function test_system_admin_creatable_roles_exclude_system_admin(): void
    {
        $values = array_map(fn (StaffRole $r) => $r->value, StaffRole::SYSTEM_ADMIN->creatableRoles());
        $this->assertNotContains('system_admin', $values);
    }

    public function test_admin_can_create_exactly_one_role(): void
    {
        $this->assertCount(1, StaffRole::ADMIN->creatableRoles());
    }

    public function test_admin_creatable_roles_include_only_support_agent(): void
    {
        $values = array_map(fn (StaffRole $r) => $r->value, StaffRole::ADMIN->creatableRoles());
        $this->assertContains('support_agent', $values);
        $this->assertNotContains('admin', $values);
        $this->assertNotContains('system_admin', $values);
    }

    public function test_support_agent_has_no_creatable_roles(): void
    {
        $this->assertCount(0, StaffRole::SUPPORT_AGENT->creatableRoles());
    }

    // ─── Enum values ──────────────────────────────────────────────────────────────

    public function test_enum_values_match_expected_strings(): void
    {
        $this->assertEquals('system_admin', StaffRole::SYSTEM_ADMIN->value);
        $this->assertEquals('admin', StaffRole::ADMIN->value);
        $this->assertEquals('support_agent', StaffRole::SUPPORT_AGENT->value);
    }

    public function test_from_string_resolves_correctly(): void
    {
        $this->assertEquals(StaffRole::SYSTEM_ADMIN, StaffRole::from('system_admin'));
        $this->assertEquals(StaffRole::ADMIN, StaffRole::from('admin'));
        $this->assertEquals(StaffRole::SUPPORT_AGENT, StaffRole::from('support_agent'));
    }
}
