<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ServiceRequest extends Model
{
    protected $fillable = [
        'client_id', 'worker_id', 'category_id', 'category_type', 'type', 'description', 'payload',
        'status', 'urgency', 'offered_price', 'final_price', 'payment_status', 'paid_at',
        'accepted_at', 'completed_at', 'expires_at', 'pin_expires_at', 'started_at', 'paused_at',
        'pause_reason', 'last_activity_at', 'last_known_lat', 'last_known_lng',
        'adjusted_price', 'price_adjustment_reason', 'client_approved_adjustment', 'price_adjusted_at',
        'carga_tipo', 'carga_peso', 'pickup_address', 'delivery_address',
        'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng',
        'delivery_photo', 'delivery_signature',
        'cancelled_at', 'cancelled_by', 'cancellation_reason', 'penalty_amount', 'penalty_applied',
        'scheduled_at', 'workers_needed', 'workers_accepted', 'recurrence', 'recurrence_days',
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
        'pin_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'price_adjusted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'penalty_amount' => 'decimal:2',
        'penalty_applied' => 'boolean',
        'payload' => 'array',
        'scheduled_at' => 'datetime',
        'workers_needed' => 'integer',
        'workers_accepted' => 'integer',
        'recurrence_days' => 'array',
    ];

    protected $appends = ['fuzzed_latitude', 'fuzzed_longitude'];

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

    public function scopeVisibleInMap($query)
    {
        return $query->where('status', 'pending')
            ->where(function($q) {
                $q->whereNotNull('client_location')
                  ->orWhere(function($subQ) {
                      // Fallback: si no hay client_location, usar coordenadas por defecto de Renaico
                      $subQ->whereNull('client_location')
                           ->whereNull('worker_id');
                  });
            })
            ->where(function($q) {
                $q->whereNull('pin_expires_at')
                  ->orWhere('pin_expires_at', '>', now());
            });
    }

    public function scopeNear($query, float $lat, float $lng, float $radiusKm)
    {
        $radiusMeters = $radiusKm * 1000;
        
        return $query->where(function($q) use ($lng, $lat, $radiusMeters) {
            // Si tiene client_location, usar consulta geográfica
            $q->whereNotNull('client_location')
              ->whereRaw(
                  "ST_DWithin(client_location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                  [$lng, $lat, $radiusMeters]
              );
        })
        ->orWhereNull('client_location') // Incluir registros sin location (fallback)
        ->selectRaw(
            "*, CASE 
                WHEN client_location IS NOT NULL 
                THEN ST_Distance(client_location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000 
                ELSE 0 
            END as distance_km",
            [$lng, $lat]
        )->orderBy('distance_km');
    }

    public function getFuzzedLatitudeAttribute(): float
    {
        if (!$this->client_location) return 0;
        
        $result = \DB::selectOne("SELECT ST_Y(client_location::geometry) as lat FROM service_requests WHERE id = ?", [$this->id]);
        if (!$result) return 0;
        
        return $result->lat + (mt_rand(-10, 10) * 0.0001);
    }

    public function getFuzzedLongitudeAttribute(): float
    {
        if (!$this->client_location) return 0;
        
        $result = \DB::selectOne("SELECT ST_X(client_location::geometry) as lng FROM service_requests WHERE id = ?", [$this->id]);
        if (!$result) return 0;
        
        return $result->lng + (mt_rand(-10, 10) * 0.0001);
    }
}
