<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'type',
        'avatar',
        'avatar_url',
        'is_active',
        'provider',
        'provider_id',
        'nickname',
        'fcm_token',
        'fcm_token_updated_at',
        'credits_balance',
        'is_pioneer',
        'is_company',
        'company_rut',
        'company_razon_social',
        'company_giro',
        'is_business',
        'business_name',
        'business_type',
        'rut',
        'rut_verified',
        'rut_verified_at',
        'email_verification_code',
        'email_verification_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_pioneer' => 'boolean',
        'is_company' => 'boolean',
    ];

    public function worker(): HasOne
    {
        return $this->hasOne(Worker::class);
    }

    public function jobsAsEmployer(): HasMany
    {
        return $this->hasMany(Job::class, 'employer_id');
    }

    public function isWorker(): bool
    {
        return $this->type === 'worker';
    }

    public function isEmployer(): bool
    {
        return $this->type === 'employer';
    }
}
