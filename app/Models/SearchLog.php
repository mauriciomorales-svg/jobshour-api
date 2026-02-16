<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SearchLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'category_requested',
        'results_found',
        'radius_used',
        'was_expanded',
        'user_agent',
        'ip_address',
    ];

    protected $casts = [
        'was_expanded' => 'boolean',
        'created_at' => 'datetime',
    ];

    public static function log(
        float $lat,
        float $lng,
        int $resultsFound,
        int $radiusUsed,
        bool $wasExpanded = false,
        ?int $categoryId = null,
        ?int $userId = null,
        ?string $userAgent = null,
        ?string $ip = null
    ): void {
        $log = static::create([
            'user_id' => $userId,
            'category_requested' => $categoryId,
            'results_found' => $resultsFound,
            'radius_used' => $radiusUsed,
            'was_expanded' => $wasExpanded,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'ip_address' => $ip,
        ]);

        DB::statement(
            "UPDATE search_logs SET coords = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?",
            [$lng, $lat, $log->id]
        );
    }
}
