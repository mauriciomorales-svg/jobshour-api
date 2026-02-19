<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkerExperience extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'title',
        'description',
        'years',
    ];

    protected $casts = [
        'years' => 'integer',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }
}
