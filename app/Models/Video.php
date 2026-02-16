<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'title',
        'description',
        'original_path',
        'processed_path',
        'thumbnail_path',
        'duration_seconds',
        'file_size_bytes',
        'status',
        'type',
        'view_count',
        'processing_metadata',
        'error_message',
    ];

    protected $casts = [
        'processing_metadata' => 'array',
        'view_count' => 'integer',
        'duration_seconds' => 'integer',
        'file_size_bytes' => 'integer',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    public function scopeProfile($query)
    {
        return $query->where('type', 'profile');
    }

    public function getVideoUrlAttribute(): ?string
    {
        return $this->processed_path 
            ? asset('storage/' . $this->processed_path) 
            : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path 
            ? asset('storage/' . $this->thumbnail_path) 
            : asset('images/video-placeholder.jpg');
    }
}
