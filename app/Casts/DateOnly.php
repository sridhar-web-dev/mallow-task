<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Calendar date with no time or timezone, stored as 'Y-m-d'.
 *
 * Laravel's built-in `date` cast writes 'Y-m-d 00:00:00'. A MySQL DATE column truncates
 * that, but SQLite keeps the string, so `ends_on >= '2026-10-31'` silently fails on the
 * boundary day. Billing is all about boundary days, so every date-only column uses this cast.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value))
            ->toDateString();
    }
}
