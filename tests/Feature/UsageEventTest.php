<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageEventTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_records_a_usage_event(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        $response = $this->postJson('/api/usage', [
            'customer_id' => $customer->id,
            'occurred_on' => '2026-09-27',
            'units' => 100,
            'idempotency_key' => 'test-key-abc',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, UsageEvent::count());
    }

    /** @test */
    public function retrying_with_same_idempotency_key_does_not_double_count(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        $payload = [
            'customer_id' => $customer->id,
            'occurred_on' => '2026-09-27',
            'units' => 100,
            'idempotency_key' => 'retry-key-001',
        ];

        $first = $this->postJson('/api/usage', $payload);
        $second = $this->postJson('/api/usage', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertEquals(1, UsageEvent::count());
        $this->assertEquals(
            $first->json('data.id'),
            $second->json('data.id')
        );
    }

    /** @test */
    public function it_rejects_invalid_payloads(): void
    {
        $response = $this->postJson('/api/usage', []);

        $response->assertStatus(422);
    }
}