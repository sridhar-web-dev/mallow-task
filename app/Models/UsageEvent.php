<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. The write path uses query-builder insertOrIgnore rather than Eloquent;
 * this model exists for reads, factories and tests.
 */
class UsageEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['merchant_id', 'customer_id', 'idempotency_key', 'quantity', 'usage_date'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'usage_date' => DateOnly::class,
        ];
    }
}
