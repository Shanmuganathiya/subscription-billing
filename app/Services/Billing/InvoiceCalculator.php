<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\UsageDailyAggregate;
use Carbon\Carbon;

class InvoiceCalculator
{
    public function __construct(private PlanRepository $planRepository)
    {
    }

    public function calculateSegment(
        Subscription $subscription,
        Carbon $cycleStart,
        Carbon $cycleEnd
    ): ?array {
        $segmentStart = Carbon::parse($subscription->starts_at)->max($cycleStart);
        $segmentEnd = $subscription->ends_at
            ? Carbon::parse($subscription->ends_at)->min($cycleEnd)
            : $cycleEnd;

        if ($segmentStart->gt($segmentEnd)) {
            return null;
        }

        $totalCycleDays = $cycleStart->copy()->startOfDay()->diffInDays($cycleEnd->copy()->startOfDay()) + 1;
        $activeDays = $segmentStart->copy()->startOfDay()->diffInDays($segmentEnd->copy()->startOfDay()) + 1;
        $dayFraction = $activeDays / $totalCycleDays;

        $plan = $this->planRepository->find($subscription->plan_id);

        $proratedBase = round($plan->base_price * $dayFraction, 2);
        $proratedIncludedUnits = (int) floor($plan->included_units * $dayFraction);

        $usedUnits = UsageDailyAggregate::where('customer_id', $subscription->customer_id)
            ->whereBetween('usage_date', [$segmentStart->toDateString(), $segmentEnd->toDateString()])
            ->sum('total_units');

        $overageUnits = max(0, $usedUnits - $proratedIncludedUnits);
        $overageCharge = round($overageUnits * $plan->overage_rate_per_unit, 2);

        return [
            'subscription_id' => $subscription->id,
            'segment_start' => $segmentStart->toDateString(),
            'segment_end' => $segmentEnd->toDateString(),
            'included_units' => $proratedIncludedUnits,
            'used_units' => (int) $usedUnits,
            'overage_units' => $overageUnits,
            'prorated_base' => $proratedBase,
            'overage_charge' => $overageCharge,
        ];
    }
}