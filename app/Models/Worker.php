<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worker extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'bio',
        'skills',
        'hourly_rate',
        'availability_status',
        'last_seen_at',
        'location',
        'location_accuracy',
        'service_area',
        'total_jobs_completed',
        'rating',
        'rating_count',
        'is_verified',
    ];

    protected $casts = [
        'skills' => 'array',
        'service_area' => 'array',
        'is_verified' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'rating' => 'decimal:1',
        'last_seen_at' => 'datetime',
    ];

    protected $appends = [];

    protected $hidden = ['location'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function showcaseVideo()
    {
        return $this->hasOne(Video::class)->where('type', 'showcase')->where('status', 'ready');
    }

    public function vcVideo()
    {
        return $this->hasOne(Video::class)->where('type', 'vc')->where('status', 'ready');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'worker_id');
    }

    // ACCESSORS DESHABILITADOS - Causan N+1 queries que cuelgan Laravel
    // Las coordenadas se extraen con selectRaw en ExpertController
    /*
    public function getLatitudeAttribute(): ?float
    {
        return $this->extractCoordinate('lat');
    }

    public function getLongitudeAttribute(): ?float
    {
        return $this->extractCoordinate('lng');
    }
    */

    private function extractCoordinate(string $type): ?float
    {
        $raw = $this->getAttributes()['location'] ?? null;
        if (!$raw) return null;

        $fn = $type === 'lat' ? 'ST_Y' : 'ST_X';
        $result = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT $fn(location::geometry) as val FROM workers WHERE id = ?", [$this->id]
        );
        return $result ? (float) $result->val : null;
    }

    public function scopeActive($query)
    {
        return $query->where('availability_status', 'active');
    }

    public function scopeContactable($query)
    {
        return $query->whereIn('availability_status', ['active', 'intermediate']);
    }

    public function scopeVisible($query)
    {
        return $query->whereIn('availability_status', ['active', 'intermediate', 'inactive']);
    }

    public function profileViews()
    {
        return $this->hasMany(ProfileView::class);
    }

    public function scopeNear($query, float $lat, float $lng, float $radiusKm = 10)
    {
        return $query->whereRaw(
            'ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$lng, $lat, $radiusKm * 1000]
        );
    }

    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function isActive(): bool
    {
        return $this->availability_status === 'active';
    }

    public function isIntermediate(): bool
    {
        return $this->availability_status === 'intermediate';
    }

    public function isInactive(): bool
    {
        return $this->availability_status === 'inactive';
    }

    public function getFuzzedLatitudeAttribute(): ?float
    {
        $lat = $this->latitude;
        return $lat ? $lat + (mt_rand(-10, 10) * 0.0001) : null;
    }

    public function getFuzzedLongitudeAttribute(): ?float
    {
        $lng = $this->longitude;
        return $lng ? $lng + (mt_rand(-10, 10) * 0.0001) : null;
    }

    /**
     * Fresh Score: rating based on LAST 10 reviews only.
     * Rewards current performance, enables redemption.
     * OPTIMIZADO: Usa rating directo para evitar N+1 queries
     */
    public function getFreshScoreAttribute(): float
    {
        // Usar rating directo en lugar de consultar reviews
        // TODO: Implementar con withAvg('reviews', 'stars') en la query principal
        return (float) $this->rating;
    }

    public function getFreshScoreCountAttribute(): int
    {
        return $this->rating_count ?? 0;
    }
}
