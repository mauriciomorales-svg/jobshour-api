<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    protected $fillable = [
        'client_id', 'worker_id', 'category_id', 'description',
        'status', 'urgency', 'offered_price', 'final_price',
        'accepted_at', 'completed_at', 'expires_at', 'started_at', 'paused_at',
        'pause_reason', 'last_activity_at', 'last_known_lat', 'last_known_lng',
        'adjusted_price', 'price_adjustment_reason', 'client_approved_adjustment', 'price_adjusted_at',
        'carga_tipo', 'carga_peso', 'pickup_address', 'delivery_address',
        'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng',
        'delivery_photo', 'delivery_signature',
    ];

    protected $casts = [
        'offered_price' => 'decimal:0',
        'final_price' => 'decimal:0',
        'adjusted_price' => 'decimal:2',
        'client_approved_adjustment' => 'boolean',
        'carga_peso' => 'decimal:2',
        'pickup_lat' => 'decimal:8',
        'pickup_lng' => 'decimal:8',
        'delivery_lat' => 'decimal:8',
        'delivery_lng' => 'decimal:8',
        'last_known_lat' => 'decimal:8',
        'last_known_lng' => 'decimal:8',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'price_adjusted_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'accepted']);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
