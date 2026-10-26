<?php

namespace App\Data;

final readonly class RecordedUsage
{
    public function __construct(
        public int $eventId,
        public int $customerId,
        public string $idempotencyKey,
        public int $quantity,
        public string $usageDate,
        // True when this request replayed an existing idempotency key and nothing new was written.
        public bool $duplicate,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->eventId,
            'customer_id' => $this->customerId,
            'idempotency_key' => $this->idempotencyKey,
            'quantity' => $this->quantity,
            'usage_date' => $this->usageDate,
            'duplicate' => $this->duplicate,
        ];
    }
}
