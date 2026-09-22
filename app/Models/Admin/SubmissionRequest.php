<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmissionRequest extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'Requested';
    public const STATUS_ACKNOWLEDGED = 'Acknowledged';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_OVERDUE = 'Overdue';
    public const STATUS_CANCELLED = 'Cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_SUBMITTED,
        self::STATUS_OVERDUE,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'request_code',
        'admin_id',
        'teacher_id',
        'document_code',
        'document_name',
        'notes',
        'due_date',
        'status',
        'acknowledged_at',
        'submitted_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'acknowledged_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function documentSubmission()
    {
        return $this->hasOne(DocumentSubmission::class);
    }

    /**
     * Apply overdue status when due date has passed and request is still open.
     */
    public function refreshOverdueStatus(): void
    {
        if (
            in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_ACKNOWLEDGED], true)
            && $this->due_date->copy()->endOfDay()->isPast()
        ) {
            $this->status = self::STATUS_OVERDUE;
            $this->saveQuietly();
        }
    }

    public static function generateRequestCode(): string
    {
        $year = now()->format('Y');
        $prefix = "SR-{$year}-";

        $last = static::where('request_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('request_code');

        $next = 1;
        if ($last) {
            $parts = explode('-', $last);
            $next = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}