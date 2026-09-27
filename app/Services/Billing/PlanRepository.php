<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

class PlanRepository
{
    private const TTL_SECONDS = 600; // 10 minutes, matches wireframe's stated cache TTL

    public function find(int $planId): ?Plan
    {
        return Cache::remember(
            $this->cacheKey($planId),
            self::TTL_SECONDS,
            fn () => Plan::find($planId)
        );
    }

    /**
     * Call this from an Observer or directly after any Plan update/delete,
     * so stale pricing never lingers past a deliberate change (rather than
     * waiting up to 10 minutes for the TTL to expire).
     */
    public function forget(int $planId): void
    {
        Cache::forget($this->cacheKey($planId));
    }

    private function cacheKey(int $planId): string
    {
        return "plan:{$planId}";
    }
}