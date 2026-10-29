<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class StaffActivationCode extends Model
{
    protected $fillable = [
        'staff_id',
        'code_hash',
        'status',
        'used_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}