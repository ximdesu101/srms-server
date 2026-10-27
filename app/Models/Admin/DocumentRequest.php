<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT        = 'Draft';
    public const STATUS_PENDING      = 'Pending';
    public const STATUS_ACKNOWLEDGED = 'Acknowledged';
    public const STATUS_PROCESSING   = 'Processing';
    public const STATUS_FULFILLED    = 'Fulfilled';
    public const STATUS_UNAVAILABLE  = 'Unavailable';
    public const STATUS_CANCELLED    = 'Cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_PROCESSING,
        self::STATUS_FULFILLED,
        self::STATUS_UNAVAILABLE,
        self::STATUS_CANCELLED,
    ];

    public const DOCUMENT_MAP = [
        'sf1'         => 'School Form 1',
        'sf2'         => 'School Form 2',
        'sf3'         => 'School Form 3',
        'sf4'         => 'School Form 4',
        'sf5'         => 'School Form 5',
        'sf6'         => 'School Form 6',
        'sf7'         => 'School Form 7',
        'sf8'         => 'School Form 8',
        'sf9'         => 'School Form 9',
        'sf10'        => 'School Form 10',
        'dtr'         => 'Biometrics and DTR',
        'acr'         => 'Activity Completion Report',
        'dpds'        => 'DepEd Partnership Database System',
        'to'          => 'Travel Order',
        'lf'          => 'Leave Form',
        'so'          => 'Special Order',
        'snsed'       => 'National Simulation Earthquake Drill',
        'transmittal' => 'Transmittal',
    ];

    protected $fillable = [
        'request_code',
        'teacher_id',
        'admin_id',
        'document_code',
        'document_name',
        'purpose',
        'status',
        'admin_message',
        'file_original_name',
        'file_stored_name',
        'file_path',
        'file_mime_type',
        'file_size',
        'acknowledged_at',
        'fulfilled_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'fulfilled_at'    => 'datetime',
            'cancelled_at'    => 'datetime',
            'file_size'       => 'integer',
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

    public static function generateRequestCode(): string
    {
        $year   = now()->format('Y');
        $prefix = "DR-{$year}-";

        $last = static::where('request_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('request_code');

        $next = 1;
        if ($last) {
            $parts = explode('-', $last);
            $next  = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /** Human-readable file size, e.g. "1.4 MB". */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
