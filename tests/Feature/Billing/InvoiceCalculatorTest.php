<?php

namespace Tests\Feature\Billing;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageDailyAggregate;
use App\Services\Billing\InvoiceCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceCalculator $calculator;
    private Merchant $merchant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(InvoiceCalculator::class);
        $this->merchant = Merchant::factory()->create();
        $this->customer = Customer::factory()->create(['merchant_id' => $this->merchant->id]);
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'merchant_id' => $this->merchant->id,
            'base_price' => 999.00,
            'included_units' => 10000,
            'overage_rate_per_unit' => 0.50,
        ], $overrides));
    }

    /** @test */
    public function full_cycle_subscription_with_no_overage_bills_full_base_price(): void
    {
        $plan = $this->makePlan();
        $subscription = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $plan->id,
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => null,
        ]);

        UsageDailyAggregate::create([
            'customer_id' => $this->customer->id,
            'usage_date' => '2026-09-15',
            'total_units' => 5000,
        ]);

        $result = $this->calculator->calculateSegment(
            $subscription,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        $this->assertEquals(999.00, $result['prorated_base']);
        $this->assertEquals(0, $result['overage_units']);
        $this->assertEquals(0.00, $result['overage_charge']);
    }

    /** @test */
    public function mid_cycle_start_prorates_base_price_and_included_units(): void
    {
        $plan = $this->makePlan();
        $subscription = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $plan->id,
            'starts_at' => '2026-09-15 00:00:00', // 16 of 30 days active
            'ends_at' => null,
        ]);

        $result = $this->calculator->calculateSegment(
            $subscription,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // 16/30 * 999 = 532.80
        $this->assertEquals(532.80, $result['prorated_base']);
        // 16/30 * 10000 = 5333 (floor)
        $this->assertEquals(5333, $result['included_units']);
    }

    /** @test */
    public function usage_beyond_included_allowance_is_charged_as_overage(): void
    {
        $plan = $this->makePlan(['included_units' => 1000, 'overage_rate_per_unit' => 0.50]);
        $subscription = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $plan->id,
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => null,
        ]);

        UsageDailyAggregate::create([
            'customer_id' => $this->customer->id,
            'usage_date' => '2026-09-10',
            'total_units' => 1500, // 500 units over the 1000 allowance
        ]);

        $result = $this->calculator->calculateSegment(
            $subscription,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        $this->assertEquals(500, $result['overage_units']);
        $this->assertEquals(250.00, $result['overage_charge']); // 500 * 0.50
    }

    /** @test */
    public function mid_cycle_plan_upgrade_splits_billing_across_two_segments(): void
    {
        $oldPlan = $this->makePlan(['name' => 'Old', 'base_price' => 999.00, 'included_units' => 10000]);
        $newPlan = $this->makePlan(['name' => 'New', 'base_price' => 1999.00, 'included_units' => 20000]);

        $oldSub = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $oldPlan->id,
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-09-14 23:59:59',
        ]);

        $newSub = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $newPlan->id,
            'starts_at' => '2026-09-15 00:00:00',
            'ends_at' => null,
        ]);

        $cycleStart = Carbon::parse('2026-09-01');
        $cycleEnd = Carbon::parse('2026-09-30');

        $oldResult = $this->calculator->calculateSegment($oldSub, $cycleStart, $cycleEnd);
        $newResult = $this->calculator->calculateSegment($newSub, $cycleStart, $cycleEnd);

        // Old segment: Sept 1-14 = 14 days of 30
        $this->assertEquals(round(999 * (14 / 30), 2), $oldResult['prorated_base']);
        // New segment: Sept 15-30 = 16 days of 30
        $this->assertEquals(round(1999 * (16 / 30), 2), $newResult['prorated_base']);
    }

    /** @test */
    public function usage_is_attributed_to_the_correct_segment_by_date(): void
    {
        $oldPlan = $this->makePlan();
        $newPlan = $this->makePlan();

        $oldSub = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $oldPlan->id,
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-09-14 23:59:59',
        ]);

        $newSub = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $newPlan->id,
            'starts_at' => '2026-09-15 00:00:00',
            'ends_at' => null,
        ]);

        UsageDailyAggregate::create(['customer_id' => $this->customer->id, 'usage_date' => '2026-09-05', 'total_units' => 300]);
        UsageDailyAggregate::create(['customer_id' => $this->customer->id, 'usage_date' => '2026-09-20', 'total_units' => 700]);

        $cycleStart = Carbon::parse('2026-09-01');
        $cycleEnd = Carbon::parse('2026-09-30');

        $oldResult = $this->calculator->calculateSegment($oldSub, $cycleStart, $cycleEnd);
        $newResult = $this->calculator->calculateSegment($newSub, $cycleStart, $cycleEnd);

        $this->assertEquals(300, $oldResult['used_units']);
        $this->assertEquals(700, $newResult['used_units']);
    }

    /** @test */
    public function subscription_outside_cycle_returns_null(): void
    {
        $plan = $this->makePlan();
        $subscription = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $plan->id,
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-31 23:59:59', // entirely before September cycle
        ]);

        $result = $this->calculator->calculateSegment(
            $subscription,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        $this->assertNull($result);
    }
}