<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDemandIntegration extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'user_id',
        'default_category_id',
        'active',
        'allowed_ips',
    ];

    protected $casts = [
        'active' => 'boolean',
        'allowed_ips' => 'array',
    ];

    /**
     * Si allowed_ips está vacío o null, cualquier IP. Si no, debe coincidir exactamente una entrada (IPv4 o IPv6).
     */
    public function clientIpIsAllowed(string $clientIp): bool
    {
        $list = $this->allowed_ips;
        if (! is_array($list) || $list === []) {
            return true;
        }

        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (strcasecmp($clientIp, $entry) === 0) {
                return true;
            }
        }

        return false;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }
}
