<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\UsageEvent;
use Illuminate\Database\QueryException;

class UsageEventService
{
    /**
     * Record a usage event idempotently.
     * Relies on a DB-level unique constraint on idempotency_key —
     * this is what actually guarantees no double-count under concurrent retries,
     * not an app-level check-then-insert (which has a race condition).
     */
    public function record(array $data): UsageEvent
    {
        $customer = Customer::findOrFail($data['customer_id']);

        try {
            return UsageEvent::create([
                'customer_id' => $customer->id,
                'merchant_id' => $customer->merchant_id,
                'occurred_on' => $data['occurred_on'],
                'units' => $data['units'],
                'idempotency_key' => $data['idempotency_key'],
            ]);
        } catch (QueryException $e) {
            // 1062 = MySQL duplicate-entry error code
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
                return UsageEvent::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
            }

            throw $e;
        }
    }
}