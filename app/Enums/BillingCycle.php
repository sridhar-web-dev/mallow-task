<?php

namespace App\Enums;

use App\Data\DateRange;
use Carbon\CarbonImmutable;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * The full calendar cycle containing $date. Cycles are calendar-anchored, so this is
     * also the proration denominator: a 31-day month prorates over 31, February over 28 or 29.
     */
    public function periodContaining(CarbonImmutable $date): DateRange
    {
        return match ($this) {
            self::Monthly => new DateRange($date->startOfMonth(), $date->endOfMonth()),
            self::Yearly => new DateRange($date->startOfYear(), $date->endOfYear()),
        };
    }
}
