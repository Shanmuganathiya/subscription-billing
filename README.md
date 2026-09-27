
## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. 


# Project - Subscription Billing & Usage-Metering System

A backend that meters customer usage against a subscription plan and generates billing invoices, for a multi-tenant SaaS-style scenario.

## Tech Stack

- Laravel 11.56.1, PHP 8.2.12
- MySQL 8 (via XAMPP's bundled MariaDB — see note below)
- Redis-compatible cache (array/file cache used for local dev; documented as swap-in for Redis in production)
- Database-backed queue (`QUEUE_CONNECTION=database`) for the exercise; documented as swap-in for Redis/SQS in production

## Setup & Run Instructions

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=subscription_billing
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

QUEUE_CONNECTION=database
CACHE_STORE=array
```

Create the database, then:
```bash
php artisan migrate
php artisan serve
```

In a separate terminal, run the queue worker (needed for the aggregation and invoicing jobs):
```bash
php artisan queue:work
```

Run the test suite:
```bash
php artisan test
```

## Architecture Summary

### Schema design

The schema splits **write-heavy raw events** from **read-heavy aggregates**, which is the central scaling decision for handling 50L+ (5 million+) usage-event rows:

- **`usage_events`** — append-only, one row per recorded event. No `updated_at` (events are never mutated). Indexed on `(customer_id, occurred_on)` and `(merchant_id, occurred_on)` to support both per-customer aggregation and per-merchant dashboard queries. A unique index on `idempotency_key` is what actually guarantees idempotency at the database level — an app-level "check then insert" has a race condition under concurrent retries; the unique constraint does not.
- **`usage_daily_aggregates`** — one row per customer per day, populated by a nightly queued job. Invoice generation and the dashboard read from this table, not from raw `usage_events`, so they stay fast regardless of how many millions of raw events exist. Unique on `(customer_id, usage_date)` so the aggregation job is naturally idempotent (`updateOrCreate`) if re-run.
- **`subscriptions`** — modeled as **segments**, not a single mutable row per customer. A plan upgrade/downgrade closes the old segment (`ends_at`) and opens a new one, rather than mutating the existing subscription in place. This is what makes mid-cycle plan changes (requirement #8) correct: invoice generation processes each overlapping segment independently and sums the results, so usage before the change is naturally billed at the old rate and usage after at the new rate.
- **`invoices` / `invoice_line_items`** — one invoice per customer per cycle, with one line item per subscription segment that overlapped that cycle. This keeps the audit trail explicit: you can see exactly which segment contributed which charge.

**At 50L+ rows:** the current indexing (composite index on `customer_id, occurred_on`) keeps the daily aggregation job's read pattern efficient (`WHERE occurred_on = ? GROUP BY customer_id`), since the job runs once per day over one day's slice of data, not the whole table. If this table needs to scale further:
- **Partitioning** by `RANGE` on `occurred_on` (monthly partitions) would let older partitions be archived/dropped independently and would keep individual partition scans small. This wasn't implemented in the exercise because MySQL's native partitioning has real limitations with foreign keys (a partitioned table's partitioning column must be part of every unique key, which conflicts with the `idempotency_key` unique index as designed) — in production this would likely mean either dropping the FK constraints on `usage_events` in favor of app-level referential checks, or moving to a partition-friendly compound unique key.
- **Read replicas** for the dashboard queries, separate from the write path, once dashboard read volume grows.

### Idempotency (`POST /usage`)

The unique constraint on `idempotency_key` is the actual guarantee. The service attempts an insert; if it hits a duplicate-key violation, it fetches and returns the existing row instead of erroring. This means two concurrent requests with the same key can never both succeed in creating a row — whichever loses the race simply reads back the winner's row. This was chosen over a `firstOrCreate`-style check-then-insert because that pattern has a TOCTOU (time-of-check-to-time-of-use) race under real concurrency.

### Queued, chunked aggregation

`AggregateDailyUsageJob` aggregates one day's `usage_events` into `usage_daily_aggregates`, chunked by `customer_id` via `chunkById()` rather than offset-based `chunk()`. `chunkById` orders by primary key, which stays correct even if rows are being inserted concurrently during the chunked read (offset-based chunking can skip or duplicate rows under concurrent writes). The `SUM`/`GROUP BY` aggregation happens in MySQL, not in PHP, so the job's own memory footprint stays flat regardless of table size.

### Invoice generation & proration

`InvoiceCalculator::calculateSegment()` is a pure function (no side effects, fully unit-tested) that, given one subscription segment and a billing cycle, computes:

1. The overlap between the segment's active dates and the cycle (`max`/`min` of the two date ranges).
2. A day-fraction (`active days / total cycle days`) used to prorate **both** the base price and the included-units allowance. Prorating the included-units allowance too — not just the price — is what prevents a customer who upgrades mid-cycle from getting a full month's usage allowance twice.
3. Usage attributed to that segment specifically, by querying `usage_daily_aggregates` for dates within the segment's own date range (not the whole cycle) — this is what correctly splits usage before/after a plan change.
4. Overage = usage beyond the prorated allowance, charged at the segment's plan's overage rate.

`GenerateInvoiceJob` finds all subscription segments overlapping a given cycle for a customer, calculates each independently, and sums them into one invoice with one line item per segment.

### Caching

Plan/pricing lookups go through `PlanRepository`, which wraps `Cache::remember()` with a 10-minute TTL (matching the wireframe's stated cache TTL). Invalidation is **write-through**: a `PlanObserver` on the `Plan` model calls `Cache::forget()` on every `saved`/`deleted` event, so a deliberate pricing change is reflected immediately rather than waiting up to 10 minutes for the TTL to lapse. The TTL itself remains as a safety net for any write path that bypasses Eloquent events.

### Rate limiting

`POST /usage` is throttled via Laravel's named rate limiter (`RateLimiter::for('usage-events', ...)`) at 120 requests/minute, matching the wireframe. **Assumption:** the wireframe specifies "per API key," but no authentication/API-key system was in scope for this exercise, so the limiter keys on request IP instead. In production this would key on an authenticated API key/tenant identifier.

### Dashboard

`MerchantDashboardService` computes all three panels from `usage_daily_aggregates`, never from raw `usage_events`:
- **Top 5 customers by usage** — straightforward sum + sort over the current cycle.
- **Projected overage revenue** — linearly extrapolates each customer's usage-so-far in the current (in-progress) cycle to a full-cycle estimate, then compares against their plan's allowance. **Assumption:** the brief doesn't specify a projection methodology; linear extrapolation from days-elapsed was chosen as the simplest reasonable model and is documented here rather than left implicit.
- **Churn risk** — flags customers whose current month's usage, extrapolated the same way, is projected to be more than 50% below last month's actual total. Customers with no prior-month usage are skipped (no baseline to compare against) rather than flagged, to avoid false positives for new customers.

### Code architecture

Controllers are kept thin — they validate (via `FormRequest`) and delegate. Business logic lives in `app/Services/`:
- `Services/UsageEventService` — usage recording + idempotency handling
- `Services/Billing/InvoiceCalculator` — pure proration/overage math (fully unit tested)
- `Services/Billing/PlanRepository` — cached plan lookups
- `Services/Dashboard/MerchantDashboardService` — dashboard aggregation queries
- `Jobs/AggregateDailyUsageJob`, `Jobs/GenerateInvoiceJob` — queued orchestration, delegating math to the services above
