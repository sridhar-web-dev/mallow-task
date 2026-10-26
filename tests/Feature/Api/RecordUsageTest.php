<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordUsageTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-26 12:00:00');

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = $this->merchant->issueApiKey();
        $this->customer = Customer::factory()->for($this->merchant)->create(['external_ref' => 'cus_1']);
        Subscription::factory()->for($this->customer)->create();
    }

    private function postUsage(array $body = [], ?string $key = 'evt-0001', ?string $apiKey = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.($apiKey ?? $this->apiKey)];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->postJson('/api/usage', $body + [
            'customer_id' => 'cus_1',
            'quantity' => 25,
            'usage_date' => '2026-10-26',
        ], $headers);
    }

    public function test_it_records_a_usage_event(): void
    {
        $this->postUsage()
            ->assertCreated()
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.quantity', 25)
            ->assertJsonPath('data.usage_date', '2026-10-26')
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('usage_events', [
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'idempotency_key' => 'evt-0001',
            'quantity' => 25,
        ]);
    }

    public function test_usage_date_defaults_to_today(): void
    {
        $this->postJson('/api/usage', ['customer_id' => 'cus_1', 'quantity' => 5], [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Idempotency-Key' => 'evt-today',
        ])->assertCreated()->assertJsonPath('data.usage_date', '2026-10-26');
    }

    public function test_a_retried_request_is_not_double_counted(): void
    {
        $first = $this->postUsage()->assertCreated();
        $retry = $this->postUsage()->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_the_idempotency_key_can_be_sent_in_the_body(): void
    {
        $this->postUsage(['idempotency_key' => 'body-key-1'], key: null)->assertCreated();
        $this->postUsage(['idempotency_key' => 'body-key-1'], key: null)->assertOk();

        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $this->postUsage()->assertCreated();

        $this->postUsage(['quantity' => 999])->assertStatus(409);

        $this->assertDatabaseCount('usage_events', 1);
        $this->assertDatabaseHas('usage_events', ['quantity' => 25]);
    }

    public function test_idempotency_keys_are_scoped_per_merchant(): void
    {
        $otherMerchant = Merchant::factory()->create();
        $otherKey = $otherMerchant->issueApiKey();
        $otherCustomer = Customer::factory()->for($otherMerchant)->create(['external_ref' => 'cus_1']);
        Subscription::factory()->for($otherCustomer)->create();

        $this->postUsage()->assertCreated();
        $this->postUsage(apiKey: $otherKey)->assertCreated();

        $this->assertDatabaseCount('usage_events', 2);
    }

    public function test_a_merchant_cannot_record_usage_for_another_merchants_customer(): void
    {
        $otherMerchant = Merchant::factory()->create();
        $otherCustomer = Customer::factory()->for($otherMerchant)->create(['external_ref' => 'cus_foreign']);
        Subscription::factory()->for($otherCustomer)->create();

        $this->postUsage(['customer_id' => 'cus_foreign'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_usage_is_rejected_for_a_customer_without_an_active_subscription(): void
    {
        $lapsed = Customer::factory()->for($this->merchant)->create(['external_ref' => 'cus_lapsed']);
        Subscription::factory()->for($lapsed)->cancelled()->create();

        $this->postUsage(['customer_id' => 'cus_lapsed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_it_requires_a_valid_api_key(): void
    {
        $this->postJson('/api/usage', ['customer_id' => 'cus_1', 'quantity' => 1])->assertUnauthorized();
        $this->postUsage(apiKey: 'mk_not-a-real-key')->assertUnauthorized();

        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_it_accepts_the_api_key_in_the_x_api_key_header(): void
    {
        $this->postJson('/api/usage', ['customer_id' => 'cus_1', 'quantity' => 1], [
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => 'evt-xheader',
        ])->assertCreated();
    }

    public function test_it_requires_an_idempotency_key(): void
    {
        $this->postUsage(key: null)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_validates_the_payload(array $override, string $field): void
    {
        $this->postUsage($override)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidPayloads(): array
    {
        return [
            'zero quantity' => [['quantity' => 0], 'quantity'],
            'negative quantity' => [['quantity' => -5], 'quantity'],
            'fractional quantity' => [['quantity' => 1.5], 'quantity'],
            'quantity above the cap' => [['quantity' => 1_000_001], 'quantity'],
            'future date' => [['usage_date' => '2026-10-27'], 'usage_date'],
            'outside late window' => [['usage_date' => '2026-10-22'], 'usage_date'],
            'bad date format' => [['usage_date' => '26/10/2026'], 'usage_date'],
            'missing customer' => [['customer_id' => ''], 'customer_id'],
        ];
    }

    public function test_late_usage_inside_the_window_is_accepted(): void
    {
        $this->postUsage(['usage_date' => '2026-10-23'])->assertCreated();
    }

    public function test_it_rate_limits_per_customer(): void
    {
        config(['billing.usage.rate_limit_per_customer' => 3]);

        foreach (range(1, 3) as $i) {
            $this->postUsage(key: "evt-ratelimit-{$i}")->assertCreated();
        }

        $this->postUsage(key: 'evt-ratelimit-4')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        // A different customer of the same merchant still has its own budget.
        $other = Customer::factory()->for($this->merchant)->create(['external_ref' => 'cus_2']);
        Subscription::factory()->for($other)->create();
        $this->postUsage(['customer_id' => 'cus_2'], key: 'evt-ratelimit-5')->assertCreated();
    }

    public function test_it_rate_limits_per_merchant(): void
    {
        config(['billing.usage.rate_limit_per_merchant' => 2]);

        $this->postUsage(key: 'evt-merchant-1')->assertCreated();
        $this->postUsage(key: 'evt-merchant-2')->assertCreated();
        $this->postUsage(key: 'evt-merchant-3')->assertTooManyRequests();

        $this->assertDatabaseCount('usage_events', 2);
    }
}
