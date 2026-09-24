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
        } else {
            $query->where('status', '!=', SubmissionRequest::STATUS_DRAFT);
        }

        $requests = $query->paginate(10);

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
        SubmissionRequest::query()
            ->whereIn('status', [SubmissionRequest::STATUS_REQUESTED, SubmissionRequest::STATUS_ACKNOWLEDGED])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => SubmissionRequest::STATUS_OVERDUE]);

        $total = SubmissionRequest::where('status', '!=', SubmissionRequest::STATUS_DRAFT)->count();
        $draft = SubmissionRequest::where('status', SubmissionRequest::STATUS_DRAFT)->count();
        $requested = SubmissionRequest::where('status', SubmissionRequest::STATUS_REQUESTED)->count();
        $acknowledged = SubmissionRequest::where('status', SubmissionRequest::STATUS_ACKNOWLEDGED)->count();
        $submitted = SubmissionRequest::where('status', SubmissionRequest::STATUS_SUBMITTED)->count();
        $overdue = SubmissionRequest::where('status', SubmissionRequest::STATUS_OVERDUE)->count();
        $cancelled = SubmissionRequest::where('status', SubmissionRequest::STATUS_CANCELLED)->count();

        return response()->json([
            'total' => $total,
            'draft' => $draft,
            'requested' => $requested,
            'acknowledged' => $acknowledged,
            'submitted' => $submitted,
            'overdue' => $overdue,
            'cancelled' => $cancelled,
        ]);
    }

    /**
     * Active registered teachers for the combobox / multi-select.
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
     * Create one or more submission requests (send or save as draft).
     *
     * Accepts:
     * - teacherIds: array of active teacher primary keys (required, min 1)
     * - documentCode: string (required)
     * - dueDate: date (required when sending; optional when draft)
     * - notes: nullable string
     * - isDraft: boolean (default false)
     */
    public function store(Request $request): JsonResponse
    {
        $isDraft = filter_var($request->input('isDraft', false), FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'teacherIds' => 'required|array|min:1',
            'teacherIds.*' => 'integer|distinct',
            'documentCode' => ['required', 'string', Rule::in(array_keys(self::DOCUMENT_MAP))],
            'notes' => 'nullable|string|max:300',
            'isDraft' => 'sometimes|boolean',
        ];

        if ($isDraft) {
            $rules['dueDate'] = 'nullable|date|after_or_equal:today';
        } else {
            $rules['dueDate'] = 'required|date|after_or_equal:today';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $teacherIds = array_values(array_unique($data['teacherIds']));

        $teachers = Teacher::query()
            ->whereIn('id', $teacherIds)
            ->where('status', 'active')
            ->get();

        if ($teachers->count() !== count($teacherIds)) {
            return response()->json([
                'message' => 'One or more selected teachers are not active registered accounts.',
                'errors' => [
                    'teacherIds' => ['One or more selected teachers are invalid or inactive.'],
                ],
            ], 422);
        }

        $admin = $request->user('admin');
        if (! $admin) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $status = $isDraft
            ? SubmissionRequest::STATUS_DRAFT
            : SubmissionRequest::STATUS_REQUESTED;

        $documentCode = $data['documentCode'];
        $documentName = self::DOCUMENT_MAP[$documentCode];
        $notes = $data['notes'] ?? null;
        $dueDate = $data['dueDate'] ?? null;

        try {
            $created = DB::transaction(function () use (
                $teachers,
                $admin,
                $status,
                $documentCode,
                $documentName,
                $notes,
                $dueDate,
                $isDraft
            ) {
                $items = [];

                foreach ($teachers as $teacher) {
                    $sr = SubmissionRequest::create([
                        'request_code' => SubmissionRequest::generateRequestCode(),
                        'admin_id' => $admin->id,
                        'teacher_id' => $teacher->id,
                        'document_code' => $documentCode,
                        'document_name' => $documentName,
                        'notes' => $notes,
                        'due_date' => $dueDate ?? now()->toDateString(),
                        'status' => $status,
                    ]);

                    if (! $isDraft) {
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
                                'due_date' => $sr->due_date instanceof \DateTimeInterface
                                    ? $sr->due_date->format('Y-m-d')
                                    : $sr->due_date,
                                'link' => '/submission-requests/' . $sr->id,
                            ]
                        );
                    }

                    $items[] = $sr;
                }

                return $items;
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $isDraft
                    ? 'Failed to save draft. Please try again.'
                    : 'Failed to send submission request. Please try again.',
            ], 500);
        }

        foreach ($created as $sr) {
            $sr->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory,status']);
        }

        $formatted = array_map(fn (SubmissionRequest $sr) => $this->formatRequest($sr), $created);

        return response()->json([
            'message' => $isDraft
                ? (count($created) === 1
                    ? 'Draft saved successfully.'
                    : count($created) . ' drafts saved successfully.')
                : (count($created) === 1
                    ? 'Submission request sent successfully.'
                    : count($created) . ' submission requests sent successfully.'),
            'requests' => $formatted,
            'request' => $formatted[0] ?? null,
        ], 201);
    }

    /**
     * Update a draft request, or send an existing draft.
     */
    public function update(Request $request, SubmissionRequest $submissionRequest): JsonResponse
    {
        $admin = $request->user('admin');
        if (! $admin) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if ((int) $submissionRequest->admin_id !== (int) $admin->id) {
            return response()->json(['message' => 'You are not authorized to modify this request.'], 403);
        }

        if ($submissionRequest->status !== SubmissionRequest::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Only draft requests can be updated.',
            ], 422);
        }

        $isDraft = filter_var($request->input('isDraft', true), FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'teacherId' => 'sometimes|integer',
            'documentCode' => ['sometimes', 'string', Rule::in(array_keys(self::DOCUMENT_MAP))],
            'notes' => 'nullable|string|max:300',
            'isDraft' => 'sometimes|boolean',
        ];

        if ($isDraft) {
            $rules['dueDate'] = 'nullable|date|after_or_equal:today';
        } else {
            $rules['dueDate'] = 'required|date|after_or_equal:today';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (isset($data['teacherId'])) {
            $teacher = Teacher::find($data['teacherId']);
            if (! $teacher || $teacher->status !== 'active') {
                return response()->json([
                    'message' => 'Selected teacher is not an active registered account.',
                    'errors' => [
                        'teacherId' => ['Selected teacher is invalid or inactive.'],
                    ],
                ], 422);
            }
            $submissionRequest->teacher_id = $teacher->id;
        } else {
            $teacher = $submissionRequest->teacher;
            if (! $teacher || $teacher->status !== 'active') {
                return response()->json([
                    'message' => 'The assigned teacher is no longer an active registered account.',
                ], 422);
            }
        }

        if (isset($data['documentCode'])) {
            $submissionRequest->document_code = $data['documentCode'];
            $submissionRequest->document_name = self::DOCUMENT_MAP[$data['documentCode']];
        }

        if (array_key_exists('notes', $data)) {
            $submissionRequest->notes = $data['notes'];
        }

        if (isset($data['dueDate'])) {
            $submissionRequest->due_date = $data['dueDate'];
        }

        if (! $isDraft && ! $submissionRequest->due_date) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'dueDate' => ['A due date is required when sending a request.'],
                ],
            ], 422);
        }

        try {
            DB::transaction(function () use ($submissionRequest, $isDraft, $teacher) {
                if ($isDraft) {
                    $submissionRequest->status = SubmissionRequest::STATUS_DRAFT;
                    $submissionRequest->save();
                } else {
                    $submissionRequest->status = SubmissionRequest::STATUS_REQUESTED;
                    $submissionRequest->save();

                    \App\Services\NotificationService::notify(
                        $teacher,
                        \App\Models\Notification::TYPE_NEW_SUBMISSION_REQUEST,
                        'New Submission Request',
                        'You have received a new document submission request from the administrator.',
                        [
                            'request_id' => $submissionRequest->id,
                            'request_code' => $submissionRequest->request_code,
                            'document_code' => $submissionRequest->document_code,
                            'document_name' => $submissionRequest->document_name,
                            'due_date' => $submissionRequest->due_date
                                ? date('Y-m-d', strtotime((string) $submissionRequest->due_date))
                                : null,
                            'link' => '/submission-requests/' . $submissionRequest->id,
                        ]
                    );
                }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update request. Please try again.',
            ], 500);
        }

        $submissionRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory,status']);

        return response()->json([
            'message' => $isDraft
                ? 'Draft updated successfully.'
                : 'Submission request sent successfully.',
            'request' => $this->formatRequest($submissionRequest),
        ]);
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
            SubmissionRequest::STATUS_DRAFT,
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