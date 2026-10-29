<?php

namespace App\Services;

use App\Models\Admin\DocumentRequest;
use App\Models\Admin\SchoolFormTemplate;
use App\Models\Admin\Staff;
use App\Models\Admin\SubmissionRequest;
use App\Models\Admin\Teacher;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Parse and validate date range from request params.
     * Returns [Carbon $from, Carbon $to] inclusive day bounds.
     */
    public function resolveDateRange(?string $from, ?string $to, string $preset = 'all'): array
    {
        $now = Carbon::now();

        if ($preset && $preset !== 'custom' && $preset !== 'all') {
            return match ($preset) {
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
                'last_30' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
                'last_90' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
                default => [null, null],
            };
        }

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to ? Carbon::parse($to)->endOfDay() : null;

        if ($fromDate && $toDate && $fromDate->gt($toDate)) {
            throw new \InvalidArgumentException('Start date must be before or equal to end date.');
        }

        return [$fromDate, $toDate];
    }

    /**
     * Apply created_at date filter to a query builder when dates are set.
     */
    public function applyDateFilter($query, ?Carbon $from, ?Carbon $to, string $column = 'created_at')
    {
        if ($from) {
            $query->where($column, '>=', $from);
        }
        if ($to) {
            $query->where($column, '<=', $to);
        }
        return $query;
    }

    /**
     * Summary metrics for cards and overall report.
     */
    public function getSummaryMetrics(?Carbon $from = null, ?Carbon $to = null): array
    {
        $teacherQuery = Teacher::query();
        $staffQuery = Staff::query();
        $templateQuery = SchoolFormTemplate::query();
        $docReqQuery = DocumentRequest::query();
        $subReqQuery = SubmissionRequest::query()->where('status', '!=', SubmissionRequest::STATUS_DRAFT);

        $this->applyDateFilter($teacherQuery, $from, $to);
        $this->applyDateFilter($staffQuery, $from, $to);
        $this->applyDateFilter($templateQuery, $from, $to);
        $this->applyDateFilter($docReqQuery, $from, $to);
        $this->applyDateFilter($subReqQuery, $from, $to);

        return [
            'teachers' => [
                'total' => (clone $teacherQuery)->count(),
                'active' => (clone $teacherQuery)->where('status', 'active')->count(),
                'inactive' => (clone $teacherQuery)->where('status', 'inactive')->count(),
                'pending' => (clone $teacherQuery)->where('status', 'pending')->count(),
            ],
            'staff' => [
                'total' => (clone $staffQuery)->count(),
                'active' => (clone $staffQuery)->where('status', 'active')->count(),
                'inactive' => (clone $staffQuery)->where('status', 'inactive')->count(),
                'pending' => (clone $staffQuery)->where('status', 'pending')->count(),
            ],
            'school_records' => [
                'total' => (clone $templateQuery)->count(),
                'current' => (clone $templateQuery)->where('status', SchoolFormTemplate::STATUS_CURRENT)->count(),
                'archived' => (clone $templateQuery)->where('status', SchoolFormTemplate::STATUS_ARCHIVED)->count(),
            ],
            'document_requests' => [
                'total' => (clone $docReqQuery)->count(),
                'pending' => (clone $docReqQuery)->where('status', DocumentRequest::STATUS_PENDING)->count(),
                'acknowledged' => (clone $docReqQuery)->where('status', DocumentRequest::STATUS_ACKNOWLEDGED)->count(),
                'processing' => (clone $docReqQuery)->where('status', DocumentRequest::STATUS_PROCESSING)->count(),
                'fulfilled' => (clone $docReqQuery)->where('status', DocumentRequest::STATUS_FULFILLED)->count(),
                'unavailable' => (clone $docReqQuery)->where('status', DocumentRequest::STATUS_UNAVAILABLE)->count(),
                'cancelled' => (clone $docReqQuery)->where('status', DocumentRequest::STATUS_CANCELLED)->count(),
            ],
            'submission_requests' => [
                'total' => (clone $subReqQuery)->count(),
                'requested' => (clone $subReqQuery)->where('status', SubmissionRequest::STATUS_REQUESTED)->count(),
                'acknowledged' => (clone $subReqQuery)->where('status', SubmissionRequest::STATUS_ACKNOWLEDGED)->count(),
                'submitted' => (clone $subReqQuery)->where('status', SubmissionRequest::STATUS_SUBMITTED)->count(),
                'overdue' => (clone $subReqQuery)->where('status', SubmissionRequest::STATUS_OVERDUE)->count(),
                'cancelled' => (clone $subReqQuery)->where('status', SubmissionRequest::STATUS_CANCELLED)->count(),
            ],
        ];
    }

    /**
     * Document request status distribution for pie/doughnut chart.
     */
    public function getRequestStatusDistribution(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = DocumentRequest::query();
        $this->applyDateFilter($query, $from, $to);

        $counts = $query
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $result = [];
        foreach (DocumentRequest::STATUSES as $status) {
            if ($status === DocumentRequest::STATUS_DRAFT) {
                continue;
            }
            $result[] = [
                'name' => $status,
                'value' => (int) ($counts[$status] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Document request trends over time (grouped by day/week/month).
     */
    public function getRequestTrends(?Carbon $from = null, ?Carbon $to = null, string $groupBy = 'month'): array
    {
        $query = DocumentRequest::query();
        $this->applyDateFilter($query, $from, $to);

        // SQLite-compatible period grouping (default driver)
        $periodExpr = match ($groupBy) {
            'day' => "strftime('%Y-%m-%d', created_at)",
            'week' => "strftime('%Y-%W', created_at)",
            default => "strftime('%Y-%m', created_at)",
        };

        $rows = $query
            ->select(DB::raw("{$periodExpr} as period"), DB::raw('count(*) as count'))
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $rows->map(fn ($r) => [
            'period' => $r->period,
            'count' => (int) $r->count,
        ])->toArray();
    }

    /**
     * Most requested document types.
     */
    public function getMostRequestedDocuments(?Carbon $from = null, ?Carbon $to = null, int $limit = 10): array
    {
        $query = DocumentRequest::query();
        $this->applyDateFilter($query, $from, $to);

        $rows = $query
            ->select('document_code', 'document_name', DB::raw('count(*) as count'))
            ->groupBy('document_code', 'document_name')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'code' => $r->document_code,
            'name' => $r->document_name ?: (DocumentRequest::DOCUMENT_MAP[$r->document_code] ?? $r->document_code),
            'count' => (int) $r->count,
        ])->toArray();
    }

    /**
     * Teacher / staff account overview.
     */
    public function getAccountOverview(): array
    {
        return [
            'teachers' => [
                'active' => Teacher::where('status', 'active')->count(),
                'inactive' => Teacher::where('status', 'inactive')->count(),
                'pending' => Teacher::where('status', 'pending')->count(),
            ],
            'staff' => [
                'active' => Staff::where('status', 'active')->count(),
                'inactive' => Staff::where('status', 'inactive')->count(),
                'pending' => Staff::where('status', 'pending')->count(),
            ],
        ];
    }

    /**
     * Paginated teacher accounts for report table.
     */
    public function getTeacherAccountsReport(array $filters = [], int $perPage = 20)
    {
        $query = Teacher::query()->orderByDesc('created_at');

        if (!empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")
                    ->orWhere('teacher_id', 'like', "%{$s}%")
                    ->orWhere('username', 'like', "%{$s}%");
            });
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * Paginated staff accounts.
     */
    public function getStaffAccountsReport(array $filters = [], int $perPage = 20)
    {
        $query = Staff::query()->orderByDesc('created_at');

        if (!empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")
                    ->orWhere('staff_id', 'like', "%{$s}%")
                    ->orWhere('username', 'like', "%{$s}%");
            });
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * School form templates / records.
     */
    public function getSchoolRecordsReport(array $filters = [], int $perPage = 20)
    {
        $query = SchoolFormTemplate::query()->orderByDesc('created_at');

        if (!empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('form_code', 'like', "%{$s}%")
                    ->orWhere('original_name', 'like', "%{$s}%");
            });
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * Document requests report.
     */
    public function getDocumentRequestsReport(array $filters = [], int $perPage = 20)
    {
        $query = DocumentRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username'])
            ->orderByDesc('created_at');

        if (!empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['document_code']) && $filters['document_code'] !== 'All') {
            $query->where('document_code', $filters['document_code']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('request_code', 'like', "%{$s}%")
                    ->orWhere('document_name', 'like', "%{$s}%")
                    ->orWhereHas('teacher', function ($tq) use ($s) {
                        $tq->where('first_name', 'like', "%{$s}%")
                            ->orWhere('last_name', 'like', "%{$s}%")
                            ->orWhere('teacher_id', 'like', "%{$s}%");
                    });
            });
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * Pending + unavailable requests.
     */
    public function getPendingUnavailableReport(array $filters = [], int $perPage = 20)
    {
        $filters['status'] = null; // override
        $query = DocumentRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username'])
            ->whereIn('status', [
                DocumentRequest::STATUS_PENDING,
                DocumentRequest::STATUS_ACKNOWLEDGED,
                DocumentRequest::STATUS_PROCESSING,
                DocumentRequest::STATUS_UNAVAILABLE,
            ])
            ->orderByDesc('created_at');

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('request_code', 'like', "%{$s}%")
                    ->orWhere('document_name', 'like', "%{$s}%")
                    ->orWhereHas('teacher', function ($tq) use ($s) {
                        $tq->where('first_name', 'like', "%{$s}%")
                            ->orWhere('last_name', 'like', "%{$s}%");
                    });
            });
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * Fulfilled requests with duration.
     */
    public function getFulfillmentReport(array $filters = [], int $perPage = 20)
    {
        $query = DocumentRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username'])
            ->where('status', DocumentRequest::STATUS_FULFILLED)
            ->orderByDesc('fulfilled_at');

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('request_code', 'like', "%{$s}%")
                    ->orWhere('document_name', 'like', "%{$s}%");
            });
        }
        if (!empty($filters['from'])) {
            $query->where('fulfilled_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('fulfilled_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * System activity (audit logs).
     */
    public function getActivityReport(array $filters = [], int $perPage = 20)
    {
        $query = AuditLog::query()->orderByDesc('created_at');

        if (!empty($filters['category']) && $filters['category'] !== 'All') {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['search'])) {
            $query->where('actor_name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * Full dataset (no pagination) for export.
     */
    public function getExportData(string $reportType, array $filters = []): array
    {
        $filters['per_page'] = 10000; // practical upper bound for school data

        return match ($reportType) {
            'teachers' => $this->getTeacherAccountsReport($filters, 10000)->items(),
            'staff' => $this->getStaffAccountsReport($filters, 10000)->items(),
            'school_records' => $this->getSchoolRecordsReport($filters, 10000)->items(),
            'document_requests' => $this->getDocumentRequestsReport($filters, 10000)->items(),
            'pending_unavailable' => $this->getPendingUnavailableReport($filters, 10000)->items(),
            'fulfillment' => $this->getFulfillmentReport($filters, 10000)->items(),
            'activity' => $this->getActivityReport($filters, 10000)->items(),
            'summary' => [],
            default => [],
        };
    }
}