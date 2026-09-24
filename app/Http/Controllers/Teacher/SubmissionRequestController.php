<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentSubmission;
use App\Models\Admin\DocumentSubmissionVersion;
use App\Models\Admin\SubmissionRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubmissionRequestController extends Controller
{
    private const ALLOWED_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

    private const MAX_FILE_KB = 10240;

    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user('teacher');
        $status = $request->query('status');

        $query = SubmissionRequest::query()
            ->where('teacher_id', $teacher->id)
            ->where('status', '!=', SubmissionRequest::STATUS_DRAFT)
            ->with(['documentSubmission', 'admin:id,username'])
            ->orderByDesc('created_at');

        if ($status && $status !== 'All' && in_array($status, SubmissionRequest::STATUSES, true)) {
            $query->where('status', $status);
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

    public function show(Request $request, SubmissionRequest $submissionRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ($submissionRequest->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($submissionRequest->status === SubmissionRequest::STATUS_DRAFT) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $submissionRequest->refreshOverdueStatus();
        $submissionRequest->load(['documentSubmission.versions', 'admin:id,username']);

        return response()->json([
            'request' => $this->formatRequest($submissionRequest, true),
        ]);
    }

    public function acknowledge(Request $request, SubmissionRequest $submissionRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ($submissionRequest->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($submissionRequest->status !== SubmissionRequest::STATUS_REQUESTED) {
            return response()->json([
                'message' => 'Only requests with status Requested can be acknowledged.',
            ], 422);
        }

        $submissionRequest->refreshOverdueStatus();
        if ($submissionRequest->status === SubmissionRequest::STATUS_OVERDUE) {
            return response()->json([
                'message' => 'This request is already overdue and cannot be acknowledged.',
            ], 422);
        }

        $submissionRequest->status = SubmissionRequest::STATUS_ACKNOWLEDGED;
        $submissionRequest->acknowledged_at = Carbon::now();
        $submissionRequest->save();

        return response()->json([
            'message' => 'Request acknowledged successfully.',
            'request' => $this->formatRequest($submissionRequest),
        ]);
    }

    public function submit(Request $request, SubmissionRequest $submissionRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ($submissionRequest->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (! in_array($submissionRequest->status, [
            SubmissionRequest::STATUS_REQUESTED,
            SubmissionRequest::STATUS_ACKNOWLEDGED,
            SubmissionRequest::STATUS_OVERDUE,
        ], true)) {
            return response()->json([
                'message' => 'This request cannot be submitted in its current status.',
            ], 422);
        }

        $existing = DocumentSubmission::where('submission_request_id', $submissionRequest->id)->first();
        if ($existing) {
            if ($existing->status === DocumentSubmission::STATUS_REVISION_REQUIRED) {
                return response()->json([
                    'message' => 'This request requires a revised document. Please use the resubmit action.',
                ], 422);
            }

            return response()->json([
                'message' => 'A document has already been submitted for this request.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_KB,
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                        $fail('Only Excel, PDF, Word, or image files are allowed.');
                    }
                    $mime = $value->getMimeType();
                    if ($mime && ! in_array($mime, self::ALLOWED_MIMES, true) && $mime !== 'application/octet-stream') {
                        $fail('Invalid file type.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString() . '.' . $ext;
        $path = $file->storeAs('submissions/' . $teacher->id, $storedName, 'public');

        try {
            $result = DB::transaction(function () use ($submissionRequest, $teacher, $file, $path, $storedName) {
                $submission = DocumentSubmission::create([
                    'submission_code' => DocumentSubmission::generateSubmissionCode(),
                    'submission_request_id' => $submissionRequest->id,
                    'teacher_id' => $teacher->id,
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'status' => DocumentSubmission::STATUS_SUBMITTED,
                    'revision_count' => 0,
                    'submitted_at' => now(),
                ]);

                DocumentSubmissionVersion::create([
                    'document_submission_id' => $submission->id,
                    'version_number' => 1,
                    'original_name' => $submission->original_name,
                    'stored_name' => $submission->stored_name,
                    'file_path' => $submission->file_path,
                    'mime_type' => $submission->mime_type,
                    'file_size' => $submission->file_size,
                    'status' => DocumentSubmission::STATUS_SUBMITTED,
                    'submitted_at' => $submission->submitted_at,
                ]);

                $submissionRequest->status = SubmissionRequest::STATUS_SUBMITTED;
                $submissionRequest->submitted_at = Carbon::now();
                if (! $submissionRequest->acknowledged_at) {
                    $submissionRequest->acknowledged_at = new Carbon();
                }
                $submissionRequest->save();

                $admin = $submissionRequest->admin;
                if ($admin) {
                    NotificationService::notify(
                        $admin,
                        Notification::TYPE_DOCUMENT_SUBMITTED,
                        'New Document Submission',
                        "{$teacher->full_name} has submitted {$submissionRequest->document_name} ({$submissionRequest->request_code}).",
                        [
                            'submission_id' => $submission->id,
                            'request_id' => $submissionRequest->id,
                            'request_code' => $submissionRequest->request_code,
                            'document_name' => $submissionRequest->document_name,
                            'teacher_name' => $teacher->full_name,
                            'link' => '/submission/submission-approval',
                        ]
                    );
                }

                return $submission;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            return response()->json([
                'message' => 'Failed to submit document. Please try again.',
            ], 500);
        }

        $submissionRequest->load(['documentSubmission', 'admin:id,username']);

        return response()->json([
            'message' => 'Documents submitted successfully.',
            'request' => $this->formatRequest($submissionRequest, true),
            'submission' => $this->formatSubmission($result),
        ]);
    }

    public function resubmit(Request $request, DocumentSubmission $documentSubmission): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ($documentSubmission->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($documentSubmission->status !== DocumentSubmission::STATUS_REVISION_REQUIRED) {
            return response()->json([
                'message' => 'Only submissions marked as Revision Required can be resubmitted.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_KB,
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                        $fail('Only Excel, PDF, Word, or image files are allowed.');
                    }
                    $mime = $value->getMimeType();
                    if ($mime && ! in_array($mime, self::ALLOWED_MIMES, true) && $mime !== 'application/octet-stream') {
                        $fail('Invalid file type.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString() . '.' . $ext;
        $path = $file->storeAs('submissions/' . $teacher->id, $storedName, 'public');

        try {
            $result = DB::transaction(function () use ($documentSubmission, $teacher, $file, $path, $storedName) {
                $documentSubmission->update([
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'status' => DocumentSubmission::STATUS_RESUBMITTED,
                    // revision_count is incremented only when admin requests revision, not on resubmit
                    'revision_note' => null,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                ]);

                DocumentSubmissionVersion::create([
                    'document_submission_id' => $documentSubmission->id,
                    'version_number' => $documentSubmission->versions()->count() + 1,
                    'original_name' => $documentSubmission->original_name,
                    'stored_name' => $documentSubmission->stored_name,
                    'file_path' => $documentSubmission->file_path,
                    'mime_type' => $documentSubmission->mime_type,
                    'file_size' => $documentSubmission->file_size,
                    'status' => DocumentSubmission::STATUS_RESUBMITTED,
                    'submitted_at' => $documentSubmission->submitted_at,
                ]);

                $sr = $documentSubmission->submissionRequest;
                $admin = $sr?->admin;
                if ($admin) {
                    NotificationService::notify(
                        $admin,
                        Notification::TYPE_DOCUMENT_RESUBMITTED,
                        'Document Resubmitted',
                        "A revised {$sr->document_name} document has been submitted by {$teacher->full_name} and is ready for review.",
                        [
                            'submission_id' => $documentSubmission->id,
                            'request_id' => $sr->id,
                            'request_code' => $sr->request_code,
                            'document_name' => $sr->document_name,
                            'teacher_name' => $teacher->full_name,
                            'link' => '/submission/submission-approval',
                        ]
                    );
                }

                return $documentSubmission->fresh(['versions', 'submissionRequest']);
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            return response()->json([
                'message' => 'Failed to resubmit document. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Revised document submitted successfully.',
            'submission' => $this->formatSubmission($result, true),
        ]);
    }

    private function formatRequest(SubmissionRequest $item, bool $detailed = false): array
    {
        return [
            'id' => $item->id,
            'request_code' => $item->request_code,
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
            'requested_by' => $item->admin?->username ?? 'Administrator',
            'submission' => $item->documentSubmission
                ? $this->formatSubmission($item->documentSubmission, $detailed)
                : null,
        ];
    }

    private function formatSubmission(DocumentSubmission $sub, bool $withVersions = false): array
    {
        $data = [
            'id' => $sub->id,
            'submission_code' => $sub->submission_code,
            'original_name' => $sub->original_name,
            'file_url' => $sub->file_path
                ? asset('storage/' . ltrim($sub->file_path, '/'))
                : null,
            'file_size' => $sub->file_size,
            'formatted_size' => $sub->formatted_size,
            'mime_type' => $sub->mime_type,
            'status' => $sub->status,
            'revision_count' => $sub->revision_count,
            'revision_note' => $sub->revision_note,
            'submitted_at' => $sub->submitted_at?->toIso8601String(),
            'reviewed_at' => $sub->reviewed_at?->toIso8601String(),
            'approved_at' => $sub->approved_at?->toIso8601String(),
        ];

        if ($withVersions) {
            $data['versions'] = $sub->versions->map(fn ($v) => [
                'version_number' => $v->version_number,
                'original_name' => $v->original_name,
                'file_url' => $v->file_path
                    ? asset('storage/' . ltrim($v->file_path, '/'))
                    : null,
                'status' => $v->status,
                'revision_note' => $v->revision_note,
                'submitted_at' => $v->submitted_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }
}