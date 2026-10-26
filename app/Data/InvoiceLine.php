<?php

namespace App\Data;

use App\Enums\LineItemType;

final readonly class InvoiceLine
{
    public function __construct(
        public LineItemType $type,
        public string $description,
        public DateRange $segment,
        public int $quantity,
        public int $unitPriceMicros,
        public int $amountMinor,
        public ?int $planPeriodId = null,
    ) {}
}
