<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentRequest;
use App\Models\Admin\SubmissionRequest;
use App\Models\Admin\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Monthly request and submission counts for the current year.
     *
     * Returns an array of 12 objects:
     *   { month: "January", document_requests: n, submission_requests: n }
     *
     * "document_requests" = teacher-initiated requests for documents (DocumentRequest)
     * "submission_requests" = admin-initiated submission requests to teachers (SubmissionRequest, excluding drafts)
     */
    public function monthlyStats(): JsonResponse
    {
        $year = now()->year;

        // Aggregate DocumentRequests by month
        $docCounts = DocumentRequest::query()
            ->selectRaw('MONTH(created_at) as month, COUNT(*) as total')
            ->whereYear('created_at', $year)
            ->groupByRaw('MONTH(created_at)')
            ->pluck('total', 'month');

        // Aggregate SubmissionRequests (exclude drafts) by month
        $subCounts = SubmissionRequest::query()
            ->selectRaw('MONTH(created_at) as month, COUNT(*) as total')
            ->whereYear('created_at', $year)
            ->where('status', '!=', SubmissionRequest::STATUS_DRAFT)
            ->groupByRaw('MONTH(created_at)')
            ->pluck('total', 'month');

        $months = [
            1  => 'January',
            2  => 'February',
            3  => 'March',
            4  => 'April',
            5  => 'May',
            6  => 'June',
            7  => 'July',
            8  => 'August',
            9  => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        $data = [];
        foreach ($months as $num => $name) {
            $data[] = [
                'month'                => $name,
                'document_requests'    => (int) ($docCounts[$num] ?? 0),
                'submission_requests'  => (int) ($subCounts[$num] ?? 0),
            ];
        }

        return response()->json([
            'year' => $year,
            'data' => $data,
        ]);
    }

    /**
     * Calendar events — both admin→teacher submission requests (outgoing)
     * and teacher→admin document requests (incoming).
     *
     * Optional query params:
     *   from  YYYY-MM-DD  start of date range (inclusive)
     *   to    YYYY-MM-DD  end of date range (inclusive)
     *
     * Each event includes a `type` field:
     *   "outgoing"  Admin created & sent to teacher (SubmissionRequest)
     *   "incoming"  Teacher submitted to admin      (DocumentRequest)
     */
    public function calendarEvents(Request $request): JsonResponse
    {
        // Auto-update overdue status before returning events
        SubmissionRequest::query()
            ->whereIn('status', [
                SubmissionRequest::STATUS_REQUESTED,
                SubmissionRequest::STATUS_ACKNOWLEDGED,
            ])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => SubmissionRequest::STATUS_OVERDUE]);

        $from = $request->query('from');
        $to   = $request->query('to');

        // ── Outgoing: admin→teacher submission requests ───────────────────
        $subQuery = SubmissionRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory'])
            ->whereNotIn('status', [
                SubmissionRequest::STATUS_DRAFT,
                SubmissionRequest::STATUS_CANCELLED,
            ])
            ->whereNotNull('due_date');

        if ($from) {
            $subQuery->whereDate('due_date', '>=', $from);
        }
        if ($to) {
            $subQuery->whereDate('due_date', '<=', $to);
        }

        $submissionEvents = $subQuery->orderBy('due_date')->get()->map(function (SubmissionRequest $item) {
            $teacher = $item->teacher;
            $teacherName = $teacher
                ? trim(implode(' ', array_filter([
                    $teacher->first_name,
                    $teacher->middle_name,
                    $teacher->last_name,
                    $teacher->suffix,
                ])))
                : null;

            return [
                'id'              => 'sr-' . $item->id,
                'raw_id'          => $item->id,
                'type'            => 'outgoing',
                'request_code'    => $item->request_code,
                'document_code'   => $item->document_code,
                'document_name'   => $item->document_name,
                'notes'           => $item->notes,
                'due_date'        => Carbon::parse($item->due_date)->toDateString(),
                'calendar_date'   => Carbon::parse($item->due_date)->toDateString(),
                'status'          => $item->status,
                'request_date'    => $item->created_at?->toDateString(),
                'acknowledged_at' => $item->acknowledged_at?->toIso8601String(),
                'submitted_at'    => $item->submitted_at?->toIso8601String(),
                'cancelled_at'    => $item->cancelled_at?->toIso8601String(),
                'teacher'         => $teacher ? [
                    'id'             => $teacher->id,
                    'teacher_id'     => $teacher->teacher_id,
                    'name'           => $teacherName,
                    'username'       => $teacher->username,
                    'position'       => $teacher->position,
                    'class_advisory' => $teacher->class_advisory,
                ] : null,
            ];
        });

        // ── Incoming: teacher→admin document requests ─────────────────────
        $docQuery = DocumentRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory'])
            ->whereNotIn('status', [
                DocumentRequest::STATUS_DRAFT,
                DocumentRequest::STATUS_CANCELLED,
            ]);

        // Position incoming requests by created_at date (no due_date on DocumentRequest)
        if ($from) {
            $docQuery->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $docQuery->whereDate('created_at', '<=', $to);
        }

        $documentEvents = $docQuery->orderBy('created_at')->get()->map(function (DocumentRequest $item) {
            $teacher = $item->teacher;
            $teacherName = $teacher
                ? trim(implode(' ', array_filter([
                    $teacher->first_name,
                    $teacher->middle_name,
                    $teacher->last_name,
                    $teacher->suffix,
                ])))
                : null;

            return [
                'id'              => 'dr-' . $item->id,
                'raw_id'          => $item->id,
                'type'            => 'incoming',
                'request_code'    => $item->request_code,
                'document_code'   => $item->document_code,
                'document_name'   => $item->document_name,
                'notes'           => $item->purpose,
                'due_date'        => null,
                'calendar_date'   => $item->created_at?->toDateString(),
                'status'          => $item->status,
                'request_date'    => $item->created_at?->toDateString(),
                'acknowledged_at' => $item->acknowledged_at?->toIso8601String(),
                'fulfilled_at'    => $item->fulfilled_at?->toIso8601String(),
                'cancelled_at'    => $item->cancelled_at?->toIso8601String(),
                'teacher'         => $teacher ? [
                    'id'             => $teacher->id,
                    'teacher_id'     => $teacher->teacher_id,
                    'name'           => $teacherName,
                    'username'       => $teacher->username,
                    'position'       => $teacher->position,
                    'class_advisory' => $teacher->class_advisory,
                ] : null,
            ];
        });

        $all = $submissionEvents->concat($documentEvents)
            ->sortBy('calendar_date')
            ->values();

        return response()->json([
            'events' => $all,
        ]);
    }

    /**
     * Aggregate dashboard metrics for all stat cards.
     *
     * Returns:
     *   teachers:     { total, active, inactive, pending }
     *   submissions:  { total, requested, acknowledged, submitted, overdue, cancelled }
     *   documents:    { total, pending, acknowledged, processing, fulfilled, unavailable, cancelled }
     */
    public function metrics(): JsonResponse
    {
        // Sync overdue before returning metrics
        SubmissionRequest::query()
            ->whereIn('status', [
                SubmissionRequest::STATUS_REQUESTED,
                SubmissionRequest::STATUS_ACKNOWLEDGED,
            ])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => SubmissionRequest::STATUS_OVERDUE]);

        return response()->json([
            'teachers' => [
                'total'    => Teacher::count(),
                'active'   => Teacher::where('status', 'active')->count(),
                'inactive' => Teacher::where('status', 'inactive')->count(),
                'pending'  => Teacher::where('status', 'pending')->count(),
            ],
            'submissions' => [
                'total'        => SubmissionRequest::where('status', '!=', SubmissionRequest::STATUS_DRAFT)->count(),
                'requested'    => SubmissionRequest::where('status', SubmissionRequest::STATUS_REQUESTED)->count(),
                'acknowledged' => SubmissionRequest::where('status', SubmissionRequest::STATUS_ACKNOWLEDGED)->count(),
                'submitted'    => SubmissionRequest::where('status', SubmissionRequest::STATUS_SUBMITTED)->count(),
                'overdue'      => SubmissionRequest::where('status', SubmissionRequest::STATUS_OVERDUE)->count(),
                'cancelled'    => SubmissionRequest::where('status', SubmissionRequest::STATUS_CANCELLED)->count(),
            ],
            'documents' => [
                'total'        => DocumentRequest::count(),
                'pending'      => DocumentRequest::where('status', DocumentRequest::STATUS_PENDING)->count(),
                'acknowledged' => DocumentRequest::where('status', DocumentRequest::STATUS_ACKNOWLEDGED)->count(),
                'processing'   => DocumentRequest::where('status', DocumentRequest::STATUS_PROCESSING)->count(),
                'fulfilled'    => DocumentRequest::where('status', DocumentRequest::STATUS_FULFILLED)->count(),
                'unavailable'  => DocumentRequest::where('status', DocumentRequest::STATUS_UNAVAILABLE)->count(),
                'cancelled'    => DocumentRequest::where('status', DocumentRequest::STATUS_CANCELLED)->count(),
            ],
        ]);
    }
}
