<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegratedQuote extends Model
{
    protected $fillable = [
        'client_id',
        'worker_id',
        'status',
        'total_amount',
        'service_amount',
        'materials_amount',
        'delivery_amount',
        'tool_wear_amount',
        'service_type',
        'service_description',
        'wants_delivery',
        'delivery_address',
        'delivery_lat',
        'delivery_lng',
        'payment_link',
        'mp_preference_id',
        'mp_payment_id',
        'mp_status',
        'metadata',
    ];

    protected $casts = [
        'wants_delivery' => 'boolean',
        'delivery_lat' => 'decimal:8',
        'delivery_lng' => 'decimal:8',
        'metadata' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(IntegratedQuoteItem::class);
    }
}

