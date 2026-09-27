<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Billing\InvoiceCalculator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $customerId,
        public readonly string $cycleStart, // 'Y-m-d'
        public readonly string $cycleEnd,   // 'Y-m-d'
    ) {
    }

    public function handle(InvoiceCalculator $calculator): void
    {
        $customer = Customer::findOrFail($this->customerId);
        // $cycleStart = Carbon::parse($this->cycleStart)->startOfDay();
        // $cycleEnd = Carbon::parse($this->cycleEnd)->endOfDay();
        $cycleStart = Carbon::parse($this->cycleStart)->startOfDay();
        $cycleEnd = Carbon::parse($this->cycleEnd)->startOfDay();

        // A customer may have multiple subscription "segments" overlapping this
        // cycle (plan upgrade/downgrade mid-cycle creates a new row rather than
        // mutating the old one — see Subscription model). We calculate each
        // segment independently, then sum them into one invoice.
        $subscriptions = $customer->subscriptions()
            ->where('starts_at', '<=', $cycleEnd)
            ->where(function ($q) use ($cycleStart) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $cycleStart);
            })
            ->orderBy('starts_at')
            ->get();

        $lineItemsData = [];
        $totalBase = 0.0;
        $totalOverage = 0.0;

        foreach ($subscriptions as $subscription) {
            $segment = $calculator->calculateSegment($subscription, $cycleStart, $cycleEnd);

            if ($segment === null) {
                continue;
            }

            $lineItemsData[] = $segment;
            $totalBase += $segment['prorated_base'];
            $totalOverage += $segment['overage_charge'];
        }

        if (empty($lineItemsData)) {
            return; // no active subscription overlapping this cycle — nothing to bill
        }

        DB::transaction(function () use ($customer, $cycleStart, $cycleEnd, $lineItemsData, $totalBase, $totalOverage) {
            $invoice = Invoice::create([
                'customer_id' => $customer->id,
                'cycle_start' => $cycleStart->toDateString(),
                'cycle_end' => $cycleEnd->toDateString(),
                'base_amount' => round($totalBase, 2),
                'overage_amount' => round($totalOverage, 2),
                'total_amount' => round($totalBase + $totalOverage, 2),
            ]);

            foreach ($lineItemsData as $item) {
                $invoice->lineItems()->create($item);
            }
        });
    }
}