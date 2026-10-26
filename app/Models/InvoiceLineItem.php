<?php

namespace App\Models;

use App\Enums\LineItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'subscription_plan_period_id',
        'type',
        'description',
        'segment_start',
        'segment_end',
        'quantity',
        'unit_price_micros',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'type' => LineItemType::class,
            'segment_start' => 'immutable_date',
            'segment_end' => 'immutable_date',
            'quantity' => 'integer',
            'unit_price_micros' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
