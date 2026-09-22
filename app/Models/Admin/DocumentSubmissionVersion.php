<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class DocumentSubmissionVersion extends Model
{
    protected $fillable = [
        'document_submission_id',
        'version_number',
        'original_name',
        'stored_name',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'revision_note',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'version_number' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function documentSubmission()
    {
        return $this->belongsTo(DocumentSubmission::class);
    }
}