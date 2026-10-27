<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentRequest;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DocumentRequestController extends Controller
{
    /**
     * List this teacher's document requests.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user('teacher');
        $status  = $request->query('status');
        $search  = $request->query('search');

        $query = DocumentRequest::query()
            ->where('teacher_id', $teacher->id)
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('request_code', 'like', "%{$search}%")
                    ->orWhere('document_name', 'like', "%{$search}%")
                    ->orWhere('document_code', 'like', "%{$search}%");
            });
        }

        if ($status && $status !== 'All' && in_array($status, DocumentRequest::STATUSES, true)) {
            $query->where('status', $status);
        } else {
            // By default exclude drafts from the main list
            $query->where('status', '!=', DocumentRequest::STATUS_DRAFT);
        }

        $requests = $query->paginate(10);

        $requests->getCollection()->transform(function (DocumentRequest $item) {
            return $this->formatRequest($item);
        });

        return response()->json($requests);
    }

    /**
     * Show a single document request (teacher must own it).
     */
    public function show(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ((int) $documentRequest->teacher_id !== (int) $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Submit a new document request to the administrator.
     * Accepts isDraft (boolean) — when true, saves locally without notifying admin.
     */
    public function store(Request $request): JsonResponse
    {
        $teacher  = $request->user('teacher');
        $isDraft  = filter_var($request->input('isDraft', false), FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'documentCode' => ['required', 'string', Rule::in(array_keys(DocumentRequest::DOCUMENT_MAP))],
            'purpose'      => 'nullable|string|max:500',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data         = $validator->validated();
        $documentCode = $data['documentCode'];
        $documentName = DocumentRequest::DOCUMENT_MAP[$documentCode];
        $purpose      = $data['purpose'] ?? null;

        if ($isDraft) {
            // Save without notifying admin
            $dr = DocumentRequest::create([
                'request_code'  => DocumentRequest::generateRequestCode(),
                'teacher_id'    => $teacher->id,
                'document_code' => $documentCode,
                'document_name' => $documentName,
                'purpose'       => $purpose,
                'status'        => DocumentRequest::STATUS_DRAFT,
            ]);

            return response()->json([
                'message' => 'Draft saved successfully.',
                'request' => $this->formatRequest($dr),
            ], 201);
        }

        // Live submission
        try {
            $docRequest = DB::transaction(function () use ($teacher, $documentCode, $documentName, $purpose) {
                $dr = DocumentRequest::create([
                    'request_code'  => DocumentRequest::generateRequestCode(),
                    'teacher_id'    => $teacher->id,
                    'document_code' => $documentCode,
                    'document_name' => $documentName,
                    'purpose'       => $purpose,
                    'status'        => DocumentRequest::STATUS_PENDING,
                ]);

                $admins = \App\Models\Admin\Admin::all();
                foreach ($admins as $admin) {
                    NotificationService::notify(
                        $admin,
                        Notification::TYPE_DOCUMENT_REQUEST_SUBMITTED,
                        'New Document Request',
                        "{$teacher->full_name} has submitted a request for {$documentName} ({$dr->request_code}).",
                        [
                            'request_id'    => $dr->id,
                            'request_code'  => $dr->request_code,
                            'document_name' => $documentName,
                            'teacher_name'  => $teacher->full_name,
                            'link'          => '/document-requests',
                        ]
                    );
                }

                return $dr;
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to submit request. Please try again.',
            ], 500);
        }

        AuditLogService::log(
            actor: $teacher,
            role: AuditLog::ROLE_TEACHER,
            action: AuditLog::ACT_DOC_REQ_CREATED,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Teacher "' . $teacher->username . '" submitted a document request for ' . $documentName . ' (' . $docRequest->request_code . ').',
            subject: $docRequest,
            subjectLabel: $docRequest->request_code,
        );

        return response()->json([
            'message' => 'Document request submitted successfully.',
            'request' => $this->formatRequest($docRequest),
        ], 201);
    }

    /**
     * Cancel a document request (teacher side — only while Pending or Acknowledged).
     */
    public function cancel(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ((int) $documentRequest->teacher_id !== (int) $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (! in_array($documentRequest->status, [
            DocumentRequest::STATUS_PENDING,
            DocumentRequest::STATUS_ACKNOWLEDGED,
        ], true)) {
            return response()->json([
                'message' => 'Only pending or acknowledged requests can be cancelled.',
            ], 422);
        }

        $documentRequest->update([
            'status'       => DocumentRequest::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
        ]);

        AuditLogService::log(
            actor: $teacher,
            role: AuditLog::ROLE_TEACHER,
            action: AuditLog::ACT_DOC_REQ_CANCELLED,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Teacher "' . $teacher->username . '" cancelled document request ' . $documentRequest->request_code . ' (' . $documentRequest->document_name . ').',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
        );

        return response()->json([
            'message' => 'Request cancelled successfully.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * List this teacher's drafts.
     */
    public function drafts(Request $request): JsonResponse
    {
        $teacher = $request->user('teacher');
        $search  = $request->query('search', '');

        $query = DocumentRequest::query()
            ->where('teacher_id', $teacher->id)
            ->where('status', DocumentRequest::STATUS_DRAFT)
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('request_code', 'like', "%{$search}%")
                    ->orWhere('document_name', 'like', "%{$search}%")
                    ->orWhere('document_code', 'like', "%{$search}%");
            });
        }

        $drafts = $query->paginate(10);
        $drafts->getCollection()->transform(fn (DocumentRequest $item) => $this->formatRequest($item));

        return response()->json($drafts);
    }

    /**
     * Delete a draft (teacher can only delete their own drafts).
     */
    public function deleteDraft(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ((int) $documentRequest->teacher_id !== (int) $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($documentRequest->status !== DocumentRequest::STATUS_DRAFT) {
            return response()->json(['message' => 'Only drafts can be deleted.'], 422);
        }

        $documentRequest->delete();

        return response()->json(['message' => 'Draft deleted successfully.']);
    }

    /**
     * Submit a draft — transitions it from Draft → Pending and notifies admin.
     */
    public function submitDraft(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $teacher = $request->user('teacher');

        if ((int) $documentRequest->teacher_id !== (int) $teacher->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($documentRequest->status !== DocumentRequest::STATUS_DRAFT) {
            return response()->json(['message' => 'Only drafts can be submitted.'], 422);
        }

        try {
            DB::transaction(function () use ($documentRequest, $teacher) {
                $documentRequest->update(['status' => DocumentRequest::STATUS_PENDING]);

                $admins = \App\Models\Admin\Admin::all();
                foreach ($admins as $admin) {
                    NotificationService::notify(
                        $admin,
                        Notification::TYPE_DOCUMENT_REQUEST_SUBMITTED,
                        'New Document Request',
                        "{$teacher->full_name} has submitted a request for {$documentRequest->document_name} ({$documentRequest->request_code}).",
                        [
                            'request_id'    => $documentRequest->id,
                            'request_code'  => $documentRequest->request_code,
                            'document_name' => $documentRequest->document_name,
                            'teacher_name'  => $teacher->full_name,
                            'link'          => '/document-requests/' . $documentRequest->id,
                        ]
                    );
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to submit draft. Please try again.'], 500);
        }

        AuditLogService::log(
            actor: $teacher,
            role: AuditLog::ROLE_TEACHER,
            action: AuditLog::ACT_DOC_REQ_CREATED,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Teacher "' . $teacher->username . '" submitted a document request (from draft) for ' . $documentRequest->document_name . ' (' . $documentRequest->request_code . ').',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
        );

        return response()->json([
            'message' => 'Request submitted successfully.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    private function formatRequest(DocumentRequest $item): array
    {
        return [
            'id'              => $item->id,
            'request_code'    => $item->request_code,
            'document_code'   => $item->document_code,
            'document_name'   => $item->document_name,
            'purpose'         => $item->purpose,
            'status'          => $item->status,
            'admin_message'   => $item->admin_message,
            'request_date'    => $item->created_at?->toDateString(),
            'acknowledged_at' => $item->acknowledged_at?->toIso8601String(),
            'fulfilled_at'    => $item->fulfilled_at?->toIso8601String(),
            'cancelled_at'    => $item->cancelled_at?->toIso8601String(),
            'created_at'      => $item->created_at?->toIso8601String(),
            'file' => $item->file_path ? [
                'original_name'  => $item->file_original_name,
                'file_url'       => asset('storage/' . ltrim($item->file_path, '/')),
                'formatted_size' => $item->formatted_size,
                'mime_type'      => $item->file_mime_type,
            ] : null,
        ];
    }
}
