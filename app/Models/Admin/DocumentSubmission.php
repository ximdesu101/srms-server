<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DocumentSubmission extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_UNDER_REVIEW = 'Under Review';
    public const STATUS_REVISION_REQUIRED = 'Revision Required';
    public const STATUS_RESUBMITTED = 'Resubmitted';
    public const STATUS_APPROVED = 'Approved';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_REVISION_REQUIRED,
        self::STATUS_RESUBMITTED,
        self::STATUS_APPROVED,
    ];

    protected $fillable = [
        'submission_code',
        'submission_request_id',
        'teacher_id',
        'admin_id',
        'original_name',
        'stored_name',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'revision_count',
        'revision_note',
        'submitted_at',
        'reviewed_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'revision_count' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function submissionRequest()
    {
        return $this->belongsTo(SubmissionRequest::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function versions()
    {
        return $this->hasMany(DocumentSubmissionVersion::class)->orderBy('version_number');
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    public function deleteFile(): void
    {
        if ($this->file_path && Storage::disk('public')->exists($this->file_path)) {
            Storage::disk('public')->delete($this->file_path);
        }
    }

    public static function generateSubmissionCode(): string
    {
        $year = now()->format('Y');
        $prefix = "SUB-{$year}-";

        $last = static::where('submission_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('submission_code');

        $next = 1;
        if ($last) {
            $parts = explode('-', $last);
            $next = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}