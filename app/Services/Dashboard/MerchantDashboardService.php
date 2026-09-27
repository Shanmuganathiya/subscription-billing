<?php

namespace App\Services\Dashboard;

use App\Models\Customer;
use App\Models\UsageDailyAggregate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MerchantDashboardService
{
    public function build(int $merchantId): array
    {
        $now = Carbon::now();
        $cycleStart = $now->copy()->startOfMonth();
        $cycleEnd = $now->copy()->endOfMonth();

        $previousCycleStart = $cycleStart->copy()->subMonthNoOverflow();
        $previousCycleEnd = $cycleStart->copy()->subDay(); // last day of previous month

        return [
            'top_customers_by_usage' => $this->topCustomersByUsage($merchantId, $cycleStart, $now),
            'projected_overage_revenue' => $this->projectedOverageRevenue($merchantId, $cycleStart, $cycleEnd, $now),
            'churn_risk_customers' => $this->churnRiskCustomers(
                $merchantId, $cycleStart, $now, $previousCycleStart, $previousCycleEnd
            ),
        ];
    }

    private function topCustomersByUsage(int $merchantId, Carbon $cycleStart, Carbon $now, int $limit = 5): array
    {
        return UsageDailyAggregate::query()
            ->join('customers', 'customers.id', '=', 'usage_daily_aggregates.customer_id')
            ->where('customers.merchant_id', $merchantId)
            ->whereBetween('usage_date', [$cycleStart->toDateString(), $now->toDateString()])
            ->select('customers.id as customer_id', 'customers.name', DB::raw('SUM(total_units) as total_usage'))
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_usage')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Projects overage revenue for the CURRENT (in-progress) cycle by linearly
     * extrapolating each customer's usage-so-far to a full-cycle estimate,
     * then comparing against their plan's included allowance.
     * This is necessarily an estimate — documented as an assumption since the
     * brief doesn't specify a projection methodology.
     */
    private function projectedOverageRevenue(int $merchantId, Carbon $cycleStart, Carbon $cycleEnd, Carbon $now): float
    {
        $daysElapsed = max(1, $cycleStart->diffInDays($now) + 1);
        $totalCycleDays = $cycleStart->diffInDays($cycleEnd) + 1;

        $customers = Customer::where('merchant_id', $merchantId)
            ->with(['subscriptions' => function ($q) use ($now) {
                $q->where('starts_at', '<=', $now)
                  ->where(function ($q2) use ($now) {
                      $q2->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                  })
                  ->latest('starts_at')
                  ->limit(1);
            }])
            ->get();

        $projectedTotal = 0.0;

        foreach ($customers as $customer) {
            $subscription = $customer->subscriptions->first();
            if (!$subscription) {
                continue;
            }

            $plan = $subscription->plan;
            $usageSoFar = UsageDailyAggregate::where('customer_id', $customer->id)
                ->whereBetween('usage_date', [$cycleStart->toDateString(), $now->toDateString()])
                ->sum('total_units');

            $projectedUsage = ($usageSoFar / $daysElapsed) * $totalCycleDays;
            $projectedOverageUnits = max(0, $projectedUsage - $plan->included_units);
            $projectedTotal += $projectedOverageUnits * $plan->overage_rate_per_unit;
        }

        return round($projectedTotal, 2);
    }

    /**
     * Churn risk: customers whose current month-to-date usage, extrapolated to
     * a full month for fair comparison, is more than 50% lower than last month's
     * total usage.
     */
    private function churnRiskCustomers(
        int $merchantId,
        Carbon $cycleStart,
        Carbon $now,
        Carbon $previousCycleStart,
        Carbon $previousCycleEnd
    ): array {
        $daysElapsed = max(1, $cycleStart->diffInDays($now) + 1);
        $daysInCurrentMonth = $cycleStart->daysInMonth;

        $customers = Customer::where('merchant_id', $merchantId)->get();
        $atRisk = [];

        foreach ($customers as $customer) {
            $currentUsage = UsageDailyAggregate::where('customer_id', $customer->id)
                ->whereBetween('usage_date', [$cycleStart->toDateString(), $now->toDateString()])
                ->sum('total_units');

            $previousUsage = UsageDailyAggregate::where('customer_id', $customer->id)
                ->whereBetween('usage_date', [$previousCycleStart->toDateString(), $previousCycleEnd->toDateString()])
                ->sum('total_units');

            if ($previousUsage <= 0) {
                continue; // no baseline to compare against
            }

            $projectedCurrentUsage = ($currentUsage / $daysElapsed) * $daysInCurrentMonth;
            $percentChange = (($projectedCurrentUsage - $previousUsage) / $previousUsage) * 100;

            if ($percentChange <= -50) {
                $atRisk[] = [
                    'customer_id' => $customer->id,
                    'name' => $customer->name,
                    'percent_change' => round($percentChange, 1),
                ];
            }
        }

        return $atRisk;
    }
}