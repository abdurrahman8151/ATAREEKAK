<?php

namespace App\Repositories;

use App\Interfaces\PolicyRepositoryInterface;
use App\Models\Policy;
use App\Models\PolicySetting;
use Illuminate\Database\Eloquent\Collection;

class PolicyRepository implements PolicyRepositoryInterface
{
    public function findByType(string $type): ?Policy
    {
        return Policy::where('type', $type)->first();
    }

    public function allPolicies(): Collection
    {
        return Policy::all()->keyBy('type');
    }

    public function upsertType(string $type, array $data): Policy
    {
        return Policy::updateOrCreate(['type' => $type], $data);
    }

    public function getSettings(): PolicySetting
    {
        return PolicySetting::firstOrCreate(['id' => 1]);
    }

    public function updateSettings(array $data): PolicySetting
    {
        $settings = $this->getSettings();
        $settings->update($data);

        return $settings->fresh();
    }

    public function getPlatformProfitPercentage(): float
    {
        return (float) $this->getSettings()->platform_profit_percentage;
    }
}
