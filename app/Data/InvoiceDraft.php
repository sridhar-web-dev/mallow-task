<?php

namespace App\Data;

use App\Enums\LineItemType;

/**
 * The calculator's output before anything is persisted. Totals are always derived from the
 * lines, so the header can never disagree with its line items.
 */
final readonly class InvoiceDraft
{
    /**
     * @param  list<InvoiceLine>  $lines
     */
    public function __construct(
        public DateRange $period,
        public array $lines,
    ) {}

    public function baseMinor(): int
    {
        return $this->sumOf(LineItemType::Base);
    }

    public function overageMinor(): int
    {
        return $this->sumOf(LineItemType::Overage);
    }

    public function totalMinor(): int
    {
        return $this->baseMinor() + $this->overageMinor();
    }

    /**
     * @return list<InvoiceLine>
     */
    public function linesOfType(LineItemType $type): array
    {
        return array_values(array_filter($this->lines, fn (InvoiceLine $line) => $line->type === $type));
    }

    private function sumOf(LineItemType $type): int
    {
        return array_sum(array_map(fn (InvoiceLine $line) => $line->amountMinor, $this->linesOfType($type)));
    }
}
