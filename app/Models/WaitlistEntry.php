<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entrada en la lista de espera para zonas sin cobertura.
 *
 * @property string $email
 * @property string|null $phone
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $zone_label
 * @property bool $notified
 * @property \Illuminate\Support\Carbon|null $notified_at
 */
class WaitlistEntry extends Model
{
    protected $fillable = [
        'email',
        'phone',
        'lat',
        'lng',
        'zone_label',
        'notified',
        'notified_at',
    ];

    protected $casts = [
        'notified'    => 'boolean',
        'notified_at' => 'datetime',
        'lat'         => 'float',
        'lng'         => 'float',
    ];
}
