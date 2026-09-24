<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentSubmission;
use App\Models\Admin\DocumentSubmissionVersion;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $status = $request->query('status');

        $query = DocumentSubmission::query()
            ->with([
                'teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username',
                'submissionRequest:id,request_code,document_code,document_name,due_date',
            ])
            ->orderByDesc('submitted_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('submission_code', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhereHas('teacher', function ($tq) use ($search) {
                        $tq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('teacher_id', 'like', "%{$search}%");
                    })
                    ->orWhereHas('submissionRequest', function ($rq) use ($search) {
                        $rq->where('request_code', 'like', "%{$search}%")
                            ->orWhere('document_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($status && $status !== 'All' && in_array($status, DocumentSubmission::STATUSES, true)) {
            $query->where('status', $status);
        }

        $submissions = $query->paginate(10);

        $submissions->getCollection()->transform(function (DocumentSubmission $item) {
            return $this->formatSubmission($item);
        });

        return response()->json($submissions);
    }

    public function metrics(): JsonResponse
    {
        $base = DocumentSubmission::query();

        return response()->json([
            'total' => (clone $base)->count(),
            'submitted' => (clone $base)->where('status', DocumentSubmission::STATUS_SUBMITTED)->count(),
            'under_review' => (clone $base)->where('status', DocumentSubmission::STATUS_UNDER_REVIEW)->count(),
            'revision_required' => (clone $base)->where('status', DocumentSubmission::STATUS_REVISION_REQUIRED)->count(),
            'resubmitted' => (clone $base)->where('status', DocumentSubmission::STATUS_RESUBMITTED)->count(),
            'approved' => (clone $base)->where('status', DocumentSubmission::STATUS_APPROVED)->count(),
        ]);
    }

    public function show(DocumentSubmission $documentSubmission): JsonResponse
    {
        $documentSubmission->load([
            'teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position',
            'submissionRequest',
            'versions',
            'admin:id,username',
        ]);

        return response()->json([
            'submission' => $this->formatSubmission($documentSubmission, true),
        ]);
    }

    public function approve(Request $request, DocumentSubmission $documentSubmission): JsonResponse
    {
        if (! in_array($documentSubmission->status, [
            DocumentSubmission::STATUS_SUBMITTED,
            DocumentSubmission::STATUS_UNDER_REVIEW,
            DocumentSubmission::STATUS_RESUBMITTED,
        ], true)) {
            return response()->json([
                'message' => 'This submission cannot be approved in its current status.',
            ], 422);
        }

        $admin = $request->user('admin');

        DB::transaction(function () use ($documentSubmission, $admin) {
            $documentSubmission->update([
                'status' => DocumentSubmission::STATUS_APPROVED,
                'admin_id' => $admin->id,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'revision_note' => null,
            ]);

            DocumentSubmissionVersion::where('document_submission_id', $documentSubmission->id)
                ->orderByDesc('version_number')
                ->limit(1)
                ->update(['status' => DocumentSubmission::STATUS_APPROVED]);

            $teacher = $documentSubmission->teacher;
            $sr = $documentSubmission->submissionRequest;
            if ($teacher) {
                NotificationService::notify(
                    $teacher,
                    Notification::TYPE_DOCUMENT_APPROVED,
                    'Document Approved',
                    "Your {$sr->document_name} submission has been reviewed and approved.",
                    [
                        'submission_id' => $documentSubmission->id,
                        'request_id' => $sr->id,
                        'request_code' => $sr->request_code,
                        'document_name' => $sr->document_name,
                        'link' => '/submission-requests/' . $sr->id,
                    ]
                );
            }
        });

        $documentSubmission->load(['teacher', 'submissionRequest', 'versions']);

        return response()->json([
            'message' => 'Document approved successfully.',
            'submission' => $this->formatSubmission($documentSubmission, true),
        ]);
    }

    public function requestRevision(Request $request, DocumentSubmission $documentSubmission): JsonResponse
    {
        if (! in_array($documentSubmission->status, [
            DocumentSubmission::STATUS_SUBMITTED,
            DocumentSubmission::STATUS_UNDER_REVIEW,
            DocumentSubmission::STATUS_RESUBMITTED,
        ], true)) {
            return response()->json([
                'message' => 'This submission cannot be sent for revision in its current status.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'revisionNote' => 'required|string|min:5|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'A revision note is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = $request->user('admin');
        $note = $validator->validated()['revisionNote'];

        DB::transaction(function () use ($documentSubmission, $admin, $note) {
            $documentSubmission->update([
                'status' => DocumentSubmission::STATUS_REVISION_REQUIRED,
                'admin_id' => $admin->id,
                'revision_note' => $note,
                'revision_count' => (int) $documentSubmission->revision_count + 1,
                'reviewed_at' => now(),
            ]);

            DocumentSubmissionVersion::create([
                'document_submission_id' => $documentSubmission->id,
                'version_number' => $documentSubmission->versions()->count() + 1,
                'original_name' => $documentSubmission->original_name,
                'stored_name' => $documentSubmission->stored_name,
                'file_path' => $documentSubmission->file_path,
                'mime_type' => $documentSubmission->mime_type,
                'file_size' => $documentSubmission->file_size,
                'status' => DocumentSubmission::STATUS_REVISION_REQUIRED,
                'revision_note' => $note,
                'submitted_at' => $documentSubmission->submitted_at,
            ]);

            $teacher = $documentSubmission->teacher;
            $sr = $documentSubmission->submissionRequest;
            if ($teacher) {
                NotificationService::notify(
                    $teacher,
                    Notification::TYPE_REVISION_REQUIRED,
                    'Revision Required',
                    "Your {$sr->document_name} submission requires revision.",
                    [
                        'submission_id' => $documentSubmission->id,
                        'request_id' => $sr->id,
                        'request_code' => $sr->request_code,
                        'document_name' => $sr->document_name,
                        'revision_note' => $note,
                        'link' => '/submission-requests/' . $sr->id,
                    ]
                );
            }
        });

        $documentSubmission->load(['teacher', 'submissionRequest', 'versions']);

        return response()->json([
            'message' => 'Revision requested successfully.',
            'submission' => $this->formatSubmission($documentSubmission, true),
        ]);
    }

    public function download(DocumentSubmission $documentSubmission): BinaryFileResponse|JsonResponse
    {
        $filePath = $documentSubmission->getAttribute('file_path');
        $originalName = $documentSubmission->getAttribute('original_name');

        if (!is_string($filePath) || $filePath === '' || !Storage::disk('public')->exists($filePath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return response()->download(
            Storage::disk('public')->path($filePath),
            is_string($originalName) && $originalName !== '' ? $originalName : basename($filePath)
        );
    }

    private function formatSubmission(DocumentSubmission $item, bool $detailed = false): array
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

        $sr = $item->submissionRequest;

        $data = [
            'id' => $item->id,
            'submission_code' => $item->submission_code,
            'teacher' => $teacher ? [
                'id' => $teacher->id,
                'teacher_id' => $teacher->teacher_id,
                'name' => $teacherName,
                'username' => $teacher->username,
            ] : null,
            'request_id' => $sr?->id,
            'request_code' => $sr?->request_code,
            'document_code' => $sr?->document_code,
            'document_name' => $sr?->document_name,
            'original_name' => $item->original_name,
            'file_url' => $item->file_path
                ? asset('storage/' . ltrim($item->file_path, '/'))
                : null,
            'formatted_size' => $item->formatted_size,
            'mime_type' => $item->mime_type,
            'status' => $item->status,
            'revision_count' => $item->revision_count,
            'revision_note' => $item->revision_note,
            'submitted_at' => $item->submitted_at?->toIso8601String(),
            'reviewed_at' => $item->reviewed_at?->toIso8601String(),
            'approved_at' => $item->approved_at?->toIso8601String(),
            'submitted_date' => $item->submitted_at?->format('m/d/y'),
        ];

        if ($detailed) {
            $data['versions'] = $item->versions->map(fn ($v) => [
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