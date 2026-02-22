<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOrder extends Model
{
    protected $fillable = [
        'worker_id', 'buyer_name', 'buyer_email', 'buyer_phone',
        'items', 'total', 'delivery', 'delivery_address',
        'status', 'mp_payment_id', 'mp_preference_id', 'mp_status',
        'expires_at', 'confirmed_at', 'rejected_at', 'reject_reason',
    ];

    protected $casts = [
        'items'        => 'array',
        'delivery'     => 'boolean',
        'expires_at'   => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at'  => 'datetime',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }
}
