<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'employer_id',
        'worker_id',
        'job_id',
        'started_at',
        'ended_at',
        'actual_hours',
        'hourly_rate',
        'total_amount',
        'payment_status',
        'employer_confirmed',
        'worker_confirmed',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'actual_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'employer_confirmed' => 'boolean',
        'worker_confirmed' => 'boolean',
    ];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('ended_at');
    }
}
