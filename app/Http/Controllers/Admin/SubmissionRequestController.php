<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\SubmissionRequest;
use App\Models\Admin\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubmissionRequestController extends Controller
{
    private const DOCUMENT_MAP = [
        'sf1' => 'School Form 1',
        'sf2' => 'School Form 2',
        'sf3' => 'School Form 3',
        'sf4' => 'School Form 4',
        'sf5' => 'School Form 5',
        'sf6' => 'School Form 6',
        'sf7' => 'School Form 7',
        'sf8' => 'School Form 8',
        'sf9' => 'School Form 9',
        'sf10' => 'School Form 10',
        'dtr' => 'Biometrics and DTR',
        'acr' => 'Activity Completion Report',
        'dpds' => 'DepEd Partnership Database System',
        'to' => 'Travel Order',
        'lf' => 'Leave Form',
        'so' => 'Special Order',
        'snsed' => 'National Simulation Earthquake Drill',
        'transmittal' => 'Transmittal',
    ];

    /**
     * List submission requests (admin).
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $status = $request->query('status');

        $query = SubmissionRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory,status'])
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('request_code', 'like', "%{$search}%")
                    ->orWhere('document_name', 'like', "%{$search}%")
                    ->orWhere('document_code', 'like', "%{$search}%")
                    ->orWhereHas('teacher', function ($tq) use ($search) {
                        $tq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('teacher_id', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        if ($status && $status !== 'All' && in_array($status, SubmissionRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        $requests = $query->paginate(10);

        // Refresh overdue status for items on this page that may have become overdue
        foreach ($requests->items() as $item) {
            $item->refreshOverdueStatus();
        }

        $requests->getCollection()->transform(function (SubmissionRequest $item) {
            return $this->formatRequest($item);
        });

        return response()->json($requests);
    }

    /**
     * Metrics for submission requests.
     */
    public function metrics(): JsonResponse
    {
        // Mark overdue in bulk for open requests past due
        SubmissionRequest::query()
            ->whereIn('status', [SubmissionRequest::STATUS_REQUESTED, SubmissionRequest::STATUS_ACKNOWLEDGED])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => SubmissionRequest::STATUS_OVERDUE]);

        $total = SubmissionRequest::count();
        $requested = SubmissionRequest::where('status', SubmissionRequest::STATUS_REQUESTED)->count();
        $acknowledged = SubmissionRequest::where('status', SubmissionRequest::STATUS_ACKNOWLEDGED)->count();
        $submitted = SubmissionRequest::where('status', SubmissionRequest::STATUS_SUBMITTED)->count();
        $overdue = SubmissionRequest::where('status', SubmissionRequest::STATUS_OVERDUE)->count();
        $cancelled = SubmissionRequest::where('status', SubmissionRequest::STATUS_CANCELLED)->count();

        return response()->json([
            'total' => $total,
            'requested' => $requested,
            'acknowledged' => $acknowledged,
            'submitted' => $submitted,
            'overdue' => $overdue,
            'cancelled' => $cancelled,
        ]);
    }

    /**
     * Active registered teachers for the combobox.
     */
    public function activeTeachers(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $teachers = Teacher::query()
            ->where('status', 'active')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('teacher_id', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'teacher_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'username', 'position', 'class_advisory']);

        $formatted = $teachers->map(function (Teacher $teacher) {
            $name = trim(implode(' ', array_filter([
                $teacher->first_name,
                $teacher->middle_name,
                $teacher->last_name,
                $teacher->suffix,
            ])));

            return [
                'id' => $teacher->id,
                'teacher_id' => $teacher->teacher_id,
                'name' => $name,
                'username' => $teacher->username,
                'position' => $teacher->position,
                'class_advisory' => $teacher->class_advisory,
            ];
        });

        return response()->json([
            'data' => $formatted,
        ]);
    }

    /**
     * Create a submission request.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teacherId' => 'required|integer|exists:teachers,id',
            'documentCode' => ['required', 'string', Rule::in(array_keys(self::DOCUMENT_MAP))],
            'dueDate' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $teacher = Teacher::find($data['teacherId']);

        if (! $teacher || $teacher->status !== 'active') {
            return response()->json([
                'message' => 'Selected teacher is not an active registered account.',
            ], 422);
        }

        $admin = $request->user('admin');

        try {
            $submissionRequest = DB::transaction(function () use ($data, $teacher, $admin) {
                $sr = SubmissionRequest::create([
                    'request_code' => SubmissionRequest::generateRequestCode(),
                    'admin_id' => $admin->id,
                    'teacher_id' => $teacher->id,
                    'document_code' => $data['documentCode'],
                    'document_name' => self::DOCUMENT_MAP[$data['documentCode']],
                    'notes' => $data['notes'] ?? null,
                    'due_date' => $data['dueDate'],
                    'status' => SubmissionRequest::STATUS_REQUESTED,
                ]);

                \App\Services\NotificationService::notify(
                    $teacher,
                    \App\Models\Notification::TYPE_NEW_SUBMISSION_REQUEST,
                    'New Submission Request',
                    'You have received a new document submission request from the administrator.',
                    [
                        'request_id' => $sr->id,
                        'request_code' => $sr->request_code,
                        'document_code' => $sr->document_code,
                        'document_name' => $sr->document_name,
                        'due_date' => $sr->due_date?->toDateString(),
                        'link' => '/submission-requests/' . $sr->id,
                    ]
                );

                return $sr;
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send submission request. Please try again.',
            ], 500);
        }

        $submissionRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory,status']);

        return response()->json([
            'message' => 'Submission request sent successfully.',
            'request' => $this->formatRequest($submissionRequest),
        ], 201);
    }

    /**
     * Show a single submission request.
     */
    public function show(SubmissionRequest $submissionRequest): JsonResponse
    {
        $submissionRequest->refreshOverdueStatus();
        $submissionRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory,status']);

        return response()->json([
            'request' => $this->formatRequest($submissionRequest),
        ]);
    }

    /**
     * Cancel a submission request (admin).
     */
    public function cancel(SubmissionRequest $submissionRequest): JsonResponse
    {
        if (! in_array($submissionRequest->status, [
            SubmissionRequest::STATUS_REQUESTED,
            SubmissionRequest::STATUS_ACKNOWLEDGED,
            SubmissionRequest::STATUS_OVERDUE,
        ], true)) {
            return response()->json([
                'message' => 'This request cannot be cancelled in its current status.',
            ], 422);
        }

        $submissionRequest->status = SubmissionRequest::STATUS_CANCELLED;
        $submissionRequest->cancelled_at = Carbon::now();
        $submissionRequest->save();

        $submissionRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory,status']);

        return response()->json([
            'message' => 'Submission request cancelled successfully.',
            'request' => $this->formatRequest($submissionRequest),
        ]);
    }

    private function formatRequest(SubmissionRequest $item): array
    {
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
            'id' => $item->id,
            'request_code' => $item->request_code,
            'teacher' => $teacher ? [
                'id' => $teacher->id,
                'teacher_id' => $teacher->teacher_id,
                'name' => $teacherName,
                'username' => $teacher->username,
                'position' => $teacher->position,
                'class_advisory' => $teacher->class_advisory,
            ] : null,
            'document_code' => $item->document_code,
            'document_name' => $item->document_name,
            'notes' => $item->notes,
            'request_date' => $item->created_at?->toDateString(),
            'due_date' => $item->due_date ? Carbon::parse($item->due_date)->toDateString() : null,
            'status' => $item->status,
            'acknowledged_at' => $item->acknowledged_at?->toIso8601String(),
            'submitted_at' => $item->submitted_at?->toIso8601String(),
            'cancelled_at' => $item->cancelled_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}