<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegratedQuoteItem extends Model
{
    protected $fillable = [
        'integrated_quote_id',
        'type',
        'reference_id',
        'title',
        'quantity',
        'unit_amount',
        'subtotal_amount',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(IntegratedQuote::class, 'integrated_quote_id');
    }
}

