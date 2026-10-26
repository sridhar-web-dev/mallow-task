<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyUsage extends Model
{
    use HasFactory;

    protected $table = 'daily_usage';

    protected $fillable = ['merchant_id', 'customer_id', 'usage_date', 'total_units', 'event_count'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'immutable_date',
            'total_units' => 'integer',
            'event_count' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
