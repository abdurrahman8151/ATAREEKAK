<?php

namespace App\Interfaces;

use App\Models\Policy;
use App\Models\PolicySetting;
use Illuminate\Database\Eloquent\Collection;

interface PolicyRepositoryInterface
{
    public function findByType(string $type): ?Policy;

    /** @return Collection<string, Policy> keyed by `type` */
    public function allPolicies(): Collection;

    public function upsertType(string $type, array $data): Policy;

    public function getSettings(): PolicySetting;

    public function updateSettings(array $data): PolicySetting;

    public function getPlatformProfitPercentage(): float;
}
