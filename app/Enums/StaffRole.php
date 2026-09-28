<?php

namespace App\Enums;

enum StaffRole: string
{
    case SYSTEM_ADMIN = 'system_admin';
    case SYCASH = 'sycash';         // Financial administrator
    case ADMIN = 'admin';
    case SUPPORT_AGENT = 'support_agent';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM_ADMIN => 'System Administrator',
            self::SYCASH => 'Financial Administrator (SyCash)',
            self::ADMIN => 'Administrator',
            self::SUPPORT_AGENT => 'Support Agent',
        };
    }

    /**
     * Numeric hierarchy level — higher = more privileged.
     */
    public function level(): int
    {
        return match ($this) {
            self::SYSTEM_ADMIN => 4,
            self::SYCASH => 3,
            self::ADMIN => 2,
            self::SUPPORT_AGENT => 1,
        };
    }

    /**
     * Restricted roles exist exactly once, seeded at deployment.
     * They can NEVER be created or deleted via the management API.
     * Password changes go through: php artisan admin:rotate-password
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::SYSTEM_ADMIN, self::SYCASH]);
    }

    /**
     * Restricted roles with access to the admin panel (reports, wallets, etc).
     */
    public function isAdminRole(): bool
    {
        return in_array($this, [self::SYSTEM_ADMIN, self::SYCASH]);
    }

    /**
     * Whether this role can create/manage the given target role.
     *
     * Rules:
     *  - system_admin → can manage admin + support_agent (not restricted roles)
     *  - sycash       → financial only, cannot manage anyone
     *  - admin        → can manage support_agent only
     *  - support_agent→ cannot manage anyone
     */
    public function canManage(self $target): bool
    {
        // Sycash is a financial-only role — no people management
        if ($this === self::SYCASH) {
            return false;
        }

        // Restricted roles can never be managed via the API
        if ($target->isRestricted()) {
            return false;
        }

        return $this->level() > $target->level();
    }

    /**
     * Returns the roles this role is allowed to create via the API.
     *
     * @return StaffRole[]
     */
    public function creatableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role) => $this->canManage($role)
        ));
    }
}
