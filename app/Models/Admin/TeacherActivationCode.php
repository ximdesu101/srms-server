<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class TeacherActivationCode extends Model
{
    protected $fillable = [
        'teacher_id',
        'code_hash',
        'status',
        'used_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}