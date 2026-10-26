<?php

namespace App\Actions;

use App\Data\RecordedUsage;
use App\Data\UsageEventData;
use App\Enums\SubscriptionStatus;
use App\Exceptions\IdempotencyKeyConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one usage event idempotently.
 *
 * Idempotency is enforced by the database, not by a "check, then insert" in PHP, which
 * would race when two retries arrive at the same moment. The unique index
 * (merchant_id, idempotency_key) lets exactly one insert win. The loser catches the
 * violation and replays the stored event. This needs no locks, no transaction and no
 * Redis, and the happy path is a single INSERT.
 */
class RecordUsageAction
{
    public function execute(UsageEventData $data): RecordedUsage
    {
        $customerId = $this->resolveSubscribedCustomerId($data);

        try {
            $eventId = DB::table('usage_events')->insertGetId([
                'merchant_id' => $data->merchantId,
                'customer_id' => $customerId,
                'idempotency_key' => $data->idempotencyKey,
                'quantity' => $data->quantity,
                'usage_date' => $data->usageDate->toDateString(),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($data, $customerId);
        }

        return new RecordedUsage(
            eventId: $eventId,
            customerId: $customerId,
            idempotencyKey: $data->idempotencyKey,
            quantity: $data->quantity,
            usageDate: $data->usageDate->toDateString(),
            duplicate: false,
        );
    }

    /**
     * One indexed query finds a customer of this merchant with an active subscription.
     * Usage for a customer who isn't subscribed could never be invoiced, so it is rejected
     * at the door instead of piling up as orphan rows.
     */
    private function resolveSubscribedCustomerId(UsageEventData $data): int
    {
        $customerId = DB::table('customers')
            ->join('subscriptions', 'subscriptions.customer_id', '=', 'customers.id')
            ->where('customers.merchant_id', $data->merchantId)
            ->where('customers.external_ref', $data->customerRef)
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->value('customers.id');

        if ($customerId === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'No customer with an active subscription matches this customer_id.',
            ]);
        }

        return (int) $customerId;
    }

    private function replay(UsageEventData $data, int $customerId): RecordedUsage
    {
        $existing = DB::table('usage_events')
            ->where('merchant_id', $data->merchantId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        $samePayload = (int) $existing->customer_id === $customerId
            && (int) $existing->quantity === $data->quantity
            && $existing->usage_date === $data->usageDate->toDateString();

        if (! $samePayload) {
            throw new IdempotencyKeyConflict($data->idempotencyKey);
        }

        return new RecordedUsage(
            eventId: (int) $existing->id,
            customerId: $customerId,
            idempotencyKey: $data->idempotencyKey,
            quantity: (int) $existing->quantity,
            usageDate: $existing->usage_date,
            duplicate: true,
        );
    }
}
