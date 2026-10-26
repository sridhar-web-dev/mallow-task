<?php

namespace App\Data;

use App\Http\Requests\StoreUsageRequest;
use Carbon\CarbonImmutable;

/**
 * A validated usage event as the domain sees it. It is decoupled from HTTP so the same
 * action can be fed by a queue consumer or a bulk import later.
 */
final readonly class UsageEventData
{
    public function __construct(
        public int $merchantId,
        public string $customerRef,
        public string $idempotencyKey,
        public int $quantity,
        public CarbonImmutable $usageDate,
    ) {}

    public static function fromRequest(StoreUsageRequest $request): self
    {
        $validated = $request->validated();

        return new self(
            merchantId: $request->merchant()->id,
            customerRef: $validated['customer_id'],
            idempotencyKey: $validated['idempotency_key'],
            quantity: (int) $validated['quantity'],
            usageDate: CarbonImmutable::createFromFormat('!Y-m-d', $validated['usage_date']),
        );
    }
}
