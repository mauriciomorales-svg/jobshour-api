<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileView extends Model
{
    protected $fillable = [
        'worker_id', 'viewer_id', 'viewer_type', 'viewer_ip', 'viewer_city', 'notified', 'viewed_at',
    ];

    protected $casts = [
        'notified' => 'boolean',
        'viewed_at' => 'datetime',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}
