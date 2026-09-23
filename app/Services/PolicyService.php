<?php

namespace App\Services;

use App\Enums\PolicyType;
use App\Interfaces\PolicyRepositoryInterface;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicySetting;

/**
 * Reads/writes the privacy and cancellation policy content shown to users,
 * plus the company/contact metadata shown alongside it. Consumed by the
 * public `PolicyController` (mobile app / any client) and the system_admin
 * `PolicyManagementController` (dashboard Settings page).
 */
class PolicyService
{
    /**
     * FAQ groups are stored in the same `policies` table as privacy/
     * cancellation (type-keyed, JSON `sections` column) since the storage
     * shape is already free-form JSON — but a group ({title, icon, entries})
     * is not a policy section ({title, intro, points}), so it is kept out of
     * `PolicyType` and handled by its own methods below rather than being
     * routed through `updatePolicy()`.
     */
    private const FAQ_TYPE = 'faq';

    public function __construct(
        private readonly PolicyRepositoryInterface $policies,
    ) {}

    public function getPayload(): array
    {
        $byType = $this->policies->allPolicies();

        return [
            'settings' => $this->formatSettings($this->policies->getSettings()),
            'privacy' => $this->formatPolicy($byType->get(PolicyType::PRIVACY->value)),
            'cancellation' => $this->formatPolicy($byType->get(PolicyType::CANCELLATION->value)),
            'faq' => $this->formatFaq($byType->get(self::FAQ_TYPE)),
        ];
    }

    public function updatePolicy(string $type, array $data, Employee $employee): Policy
    {
        if (!in_array($type, PolicyType::values(), true)) {
            throw new \InvalidArgumentException('Invalid policy type.');
        }

        return $this->policies->upsertType($type, [
            'sections' => $data['sections'],
            'last_updated_label' => $data['last_updated_label'] ?? null,
            'updated_by' => $employee->id,
        ]);
    }

    public function updateFaq(array $data, Employee $employee): Policy
    {
        return $this->policies->upsertType(self::FAQ_TYPE, [
            'sections' => $data['groups'],
            'last_updated_label' => null,
            'updated_by' => $employee->id,
        ]);
    }

    public function updateSettings(array $data, Employee $employee): PolicySetting
    {
        return $this->policies->updateSettings([
            ...$data,
            'updated_by' => $employee->id,
        ]);
    }

    private function formatPolicy(?Policy $policy): ?array
    {
        if (!$policy) {
            return null;
        }

        return [
            'last_updated_label' => $policy->last_updated_label,
            'sections' => $policy->sections,
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }

    private function formatFaq(?Policy $policy): array
    {
        return [
            'groups' => $policy?->sections ?? [],
            'updated_at' => $policy?->updated_at?->toIso8601String(),
        ];
    }

    private function formatSettings(PolicySetting $settings): array
    {
        return [
            'company' => $settings->company,
            'app_name' => $settings->app_name,
            'contact_email' => $settings->contact_email,
            'contact_phone' => $settings->contact_phone,
            'contact_address' => $settings->contact_address,
            'consent_label' => $settings->consent_label,
            'platform_profit_percentage' => (float) $settings->platform_profit_percentage,
        ];
    }
}
