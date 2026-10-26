<?php

namespace App\Support;

/**
 * Integer-only money arithmetic. Amounts are in minor units (paise) and unit rates in
 * micros (millionths of the major unit), so no floats ever touch an invoice.
 */
final class Money
{
    public const MICROS_PER_MINOR = 10_000;

    /**
     * Divides two non-negative integers, rounding half up. Rounding happens once per
     * line item, never on intermediate values.
     */
    public static function roundDiv(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    public static function microsToMinor(int $micros): int
    {
        return self::roundDiv($micros, self::MICROS_PER_MINOR);
    }
}
