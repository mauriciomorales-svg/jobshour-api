<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Nudge extends Model
{
    protected $fillable = ['message', 'category', 'weight', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'weight' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get a weighted-random nudge.
     * Higher weight = higher probability of being selected.
     */
    public static function random(): ?self
    {
        $nudges = static::active()->get();
        if ($nudges->isEmpty()) return null;

        $totalWeight = $nudges->sum('weight');
        $roll = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($nudges as $nudge) {
            $cumulative += $nudge->weight;
            if ($roll <= $cumulative) return $nudge;
        }

        return $nudges->last();
    }
}
