<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDemandPartnerPublish extends Model
{
    protected $fillable = [
        'store_demand_integration_id',
        'dedupe_key',
        'service_request_id',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(StoreDemandIntegration::class, 'store_demand_integration_id');
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
