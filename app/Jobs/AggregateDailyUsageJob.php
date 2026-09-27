<?php

namespace App\Jobs;

use App\Models\UsageDailyAggregate;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AggregateDailyUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 min, generous for large chunks

    public function __construct(
        public readonly string $date // 'Y-m-d' — the day to aggregate
    ) {
    }

    public function handle(): void
    {
        $date = Carbon::parse($this->date)->toDateString();

        // Chunk by customer_id to keep memory flat even with 50L+ rows.
        // chunkById is safer than offset-based chunk() under concurrent inserts,
        // since it orders by primary key instead of relying on stable offsets.
        UsageEvent::query()
            ->where('occurred_on', $date)
            ->select('customer_id', DB::raw('SUM(units) as total_units'))
            ->groupBy('customer_id')
            ->orderBy('customer_id')
            ->chunkById(500, function ($rows) use ($date) {
                foreach ($rows as $row) {
                    UsageDailyAggregate::updateOrCreate(
                        ['customer_id' => $row->customer_id, 'usage_date' => $date],
                        ['total_units' => $row->total_units]
                    );
                }
            }, column: 'customer_id');
    }
}