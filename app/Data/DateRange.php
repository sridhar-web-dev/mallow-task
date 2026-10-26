<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * An inclusive range of calendar days. Both ends are always midnight, and days()
 * counts both ends, so 1–31 October is 31 days.
 */
final readonly class DateRange
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(DateTimeInterface|string $start, DateTimeInterface|string $end)
    {
        $this->start = CarbonImmutable::parse($start)->startOfDay();
        $this->end = CarbonImmutable::parse($end)->startOfDay();

        if ($this->end->lt($this->start)) {
            throw new InvalidArgumentException("Range end {$this->end->toDateString()} is before start {$this->start->toDateString()}.");
        }
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function contains(DateTimeInterface|string $date): bool
    {
        $date = CarbonImmutable::parse($date)->startOfDay();

        return $date->gte($this->start) && $date->lte($this->end);
    }

    public function intersect(self $other): ?self
    {
        $start = $this->start->max($other->start);
        $end = $this->end->min($other->end);

        return $end->lt($start) ? null : new self($start, $end);
    }

    public function label(): string
    {
        return $this->start->toDateString().' to '.$this->end->toDateString();
    }
}
