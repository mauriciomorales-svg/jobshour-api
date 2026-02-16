<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Job extends Model
{
    use HasFactory;

    protected $fillable = [
        'employer_id',
        'worker_id',
        'title',
        'description',
        'skills_required',
        'location',
        'address',
        'budget',
        'payment_type',
        'urgency',
        'status',
        'scheduled_at',
        'estimated_duration_minutes',
        'started_at',
        'completed_at',
        'final_price',
    ];

    protected $casts = [
        'skills_required' => 'array',
        'budget' => 'decimal:2',
        'final_price' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_duration_minutes' => 'integer',
    ];

    protected $appends = ['latitude', 'longitude'];

    protected $hidden = ['location'];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function getLatitudeAttribute(): ?float
    {
        return $this->extractCoordinate('lat');
    }

    public function getLongitudeAttribute(): ?float
    {
        return $this->extractCoordinate('lng');
    }

    private function extractCoordinate(string $type): ?float
    {
        $raw = $this->getAttributes()['location'] ?? null;
        if (!$raw) return null;

        $fn = $type === 'lat' ? 'ST_Y' : 'ST_X';
        $result = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT $fn(location::geometry) as val FROM jobs WHERE id = ?", [$this->id]
        );
        return $result ? (float) $result->val : null;
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeNear($query, float $lat, float $lng, float $radiusKm = 10)
    {
        return $query->whereRaw(
            'ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$lng, $lat, $radiusKm * 1000]
        );
    }

    public function scopeByUrgency($query)
    {
        return $query->orderByRaw("FIELD(urgency, 'urgent', 'high', 'medium', 'low')");
    }
}
