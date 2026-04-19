<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAnalyticsEvent extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'payload',
        'user_id',
        'client_ts',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'client_ts' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
