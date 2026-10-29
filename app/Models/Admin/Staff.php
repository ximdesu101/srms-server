<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'staffs';

    protected $fillable = [
        'staff_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'username',
        'password',
        'position',
        'status',
        'activated_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password'     => 'hashed',
            'activated_at' => 'datetime',
        ];
    }

    public function activationCodes()
    {
        return $this->hasMany(StaffActivationCode::class);
    }

    public function notifications()
    {
        return $this->morphMany(\App\Models\Notification::class, 'notifiable')->orderByDesc('created_at');
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }
}