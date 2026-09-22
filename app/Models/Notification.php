<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Notification extends Model
{
    public const TYPE_NEW_SUBMISSION_REQUEST = 'NewSubmissionRequest';
    public const TYPE_DOCUMENT_SUBMITTED = 'DocumentSubmitted';
    public const TYPE_REVISION_REQUIRED = 'RevisionRequired';
    public const TYPE_DOCUMENT_APPROVED = 'DocumentApproved';
    public const TYPE_DOCUMENT_RESUBMITTED = 'DocumentResubmitted';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function isUnread(): bool
    {
        return is_null($this->read_at);
    }
}