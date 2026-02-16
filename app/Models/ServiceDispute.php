<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceDispute extends Model
{
    protected $fillable = [
        'service_request_id',
        'reported_by',
        'reason',
        'description',
        'evidence_photos',
        'worker_lat',
        'worker_lng',
        'compensation_amount',
        'auto_approved',
        'status',
        'admin_notes',
        'resolved_at',
    ];

    protected $casts = [
        'evidence_photos' => 'array',
        'worker_lat' => 'decimal:8',
        'worker_lng' => 'decimal:8',
        'compensation_amount' => 'decimal:2',
        'auto_approved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
