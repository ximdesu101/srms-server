<?php

namespace App\Http\Controllers\Admin;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class DocumentRequestController extends Controller
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

    private const MAX_FILE_KB = 10240; // 10 MB

    /**
     * Paginated list of all teacher document requests (admin view).
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $status = $request->query('status');

        $query = DocumentRequest::query()
            ->with(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory'])
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

        if ($status && $status !== 'All' && in_array($status, DocumentRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        $requests = $query->paginate(10);

        $requests->getCollection()->transform(function (DocumentRequest $item) {
            return $this->formatRequest($item);
        });

        return response()->json($requests);
    }

    /**
     * Metrics counts by status.
     */
    public function metrics(): JsonResponse
    {
        $base = DocumentRequest::query();

        return response()->json([
            'total'        => (clone $base)->count(),
            'pending'      => (clone $base)->where('status', DocumentRequest::STATUS_PENDING)->count(),
            'acknowledged' => (clone $base)->where('status', DocumentRequest::STATUS_ACKNOWLEDGED)->count(),
            'processing'   => (clone $base)->where('status', DocumentRequest::STATUS_PROCESSING)->count(),
            'fulfilled'    => (clone $base)->where('status', DocumentRequest::STATUS_FULFILLED)->count(),
            'unavailable'  => (clone $base)->where('status', DocumentRequest::STATUS_UNAVAILABLE)->count(),
            'cancelled'    => (clone $base)->where('status', DocumentRequest::STATUS_CANCELLED)->count(),
        ]);
    }

    /**
     * Show a single document request.
     */
    public function show(DocumentRequest $documentRequest): JsonResponse
    {
        $documentRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory']);

        return response()->json([
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Acknowledge a pending request — signals the teacher that the admin has seen it.
     */
    public function acknowledge(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        if ($documentRequest->status !== DocumentRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending requests can be acknowledged.',
            ], 422);
        }

        $admin = $request->user('admin');

        DB::transaction(function () use ($documentRequest, $admin) {
            $documentRequest->update([
                'status'          => DocumentRequest::STATUS_ACKNOWLEDGED,
                'admin_id'        => $admin->id,
                'acknowledged_at' => Carbon::now(),
            ]);

            $teacher = $documentRequest->teacher;
            if ($teacher) {
                NotificationService::notify(
                    $teacher,
                    Notification::TYPE_DOCUMENT_REQUEST_ACKNOWLEDGED,
                    'Request Acknowledged',
                    "Your request for {$documentRequest->document_name} ({$documentRequest->request_code}) has been acknowledged by the administrator.",
                    [
                        'request_id'    => $documentRequest->id,
                        'request_code'  => $documentRequest->request_code,
                        'document_name' => $documentRequest->document_name,
                        'link'          => '/document-requests',
                    ]
                );
            }
        });

        $documentRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory']);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_DOC_REQ_ACKNOWLEDGED,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Admin acknowledged document request ' . $documentRequest->request_code . ' (' . $documentRequest->document_name . ') from teacher "' . ($documentRequest->teacher->username ?? 'unknown') . '".',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
        );

        return response()->json([
            'message' => 'Request acknowledged successfully.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Mark a request as Processing — admin is actively working on it.
     */
    public function markProcessing(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        if (! in_array($documentRequest->status, [
            DocumentRequest::STATUS_PENDING,
            DocumentRequest::STATUS_ACKNOWLEDGED,
        ], true)) {
            return response()->json([
                'message' => 'Only pending or acknowledged requests can be marked as processing.',
            ], 422);
        }

        $admin = $request->user('admin');

        DB::transaction(function () use ($documentRequest, $admin) {
            $documentRequest->update([
                'status'   => DocumentRequest::STATUS_PROCESSING,
                'admin_id' => $admin->id,
                'acknowledged_at' => $documentRequest->acknowledged_at ?? Carbon::now(),
            ]);

            $teacher = $documentRequest->teacher;
            if ($teacher) {
                NotificationService::notify(
                    $teacher,
                    Notification::TYPE_DOCUMENT_REQUEST_PROCESSING,
                    'Request Being Processed',
                    "Your request for {$documentRequest->document_name} ({$documentRequest->request_code}) is currently being processed by the administrator.",
                    [
                        'request_id'    => $documentRequest->id,
                        'request_code'  => $documentRequest->request_code,
                        'document_name' => $documentRequest->document_name,
                        'link'          => '/document-requests',
                    ]
                );
            }
        });

        $documentRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory']);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_DOC_REQ_PROCESSING,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Admin marked document request ' . $documentRequest->request_code . ' (' . $documentRequest->document_name . ') as processing.',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
        );

        return response()->json([
            'message' => 'Request marked as processing.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Fulfill a request — upload the requested document and optionally add a message.
     */
    public function fulfill(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        if (in_array($documentRequest->status, [
            DocumentRequest::STATUS_FULFILLED,
            DocumentRequest::STATUS_CANCELLED,
        ], true)) {
            return response()->json([
                'message' => 'This request has already been fulfilled or cancelled.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file'          => [
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
            'admin_message' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $admin   = $request->user('admin');
        $file    = $request->file('file');
        $ext     = strtolower($file->getClientOriginalExtension());
        $stored  = Str::uuid()->toString() . '.' . $ext;
        $path    = $file->storeAs('document-requests/' . $documentRequest->teacher_id, $stored, 'public');

        // Delete previous fulfilled file if it exists
        $oldPath = $documentRequest->file_path;

        try {
            DB::transaction(function () use ($documentRequest, $admin, $file, $path, $stored, $request) {
                $documentRequest->update([
                    'status'             => DocumentRequest::STATUS_FULFILLED,
                    'admin_id'           => $admin->id,
                    'admin_message'      => $request->input('admin_message') ?? null,
                    'file_original_name' => $file->getClientOriginalName(),
                    'file_stored_name'   => $stored,
                    'file_path'          => $path,
                    'file_mime_type'     => $file->getMimeType(),
                    'file_size'          => $file->getSize(),
                    'fulfilled_at'       => Carbon::now(),
                    'acknowledged_at'    => $documentRequest->acknowledged_at ?? Carbon::now(),
                ]);

                $teacher = $documentRequest->teacher;
                if ($teacher) {
                    NotificationService::notify(
                        $teacher,
                        Notification::TYPE_DOCUMENT_REQUEST_FULFILLED,
                        'Document Ready',
                        "Your requested document {$documentRequest->document_name} ({$documentRequest->request_code}) is now available for download.",
                        [
                            'request_id'    => $documentRequest->id,
                            'request_code'  => $documentRequest->request_code,
                            'document_name' => $documentRequest->document_name,
                            'link'          => '/document-requests',
                        ]
                    );
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            return response()->json([
                'message' => 'Failed to fulfill request. Please try again.',
            ], 500);
        }

        // Clean up old file after successful transaction
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        $documentRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory']);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_DOC_REQ_FULFILLED,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Admin fulfilled document request ' . $documentRequest->request_code . ' (' . $documentRequest->document_name . ') — document uploaded for teacher "' . ($documentRequest->teacher->username ?? 'unknown') . '".',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
            metadata: ['file_name' => $documentRequest->file_original_name],
        );

        return response()->json([
            'message' => 'Request fulfilled successfully.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Mark a request as unavailable and send the teacher a message.
     */
    public function markUnavailable(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        if (in_array($documentRequest->status, [
            DocumentRequest::STATUS_FULFILLED,
            DocumentRequest::STATUS_CANCELLED,
        ], true)) {
            return response()->json([
                'message' => 'This request has already been fulfilled or cancelled.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'admin_message' => 'required|string|min:5|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'A message explaining the unavailability is required.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $admin   = $request->user('admin');
        $message = $validator->validated()['admin_message'];

        DB::transaction(function () use ($documentRequest, $admin, $message) {
            $documentRequest->update([
                'status'          => DocumentRequest::STATUS_UNAVAILABLE,
                'admin_id'        => $admin->id,
                'admin_message'   => $message,
                'acknowledged_at' => $documentRequest->acknowledged_at ?? Carbon::now(),
            ]);

            $teacher = $documentRequest->teacher;
            if ($teacher) {
                NotificationService::notify(
                    $teacher,
                    Notification::TYPE_DOCUMENT_REQUEST_UNAVAILABLE,
                    'Document Unavailable',
                    "Your requested document {$documentRequest->document_name} ({$documentRequest->request_code}) is currently unavailable.",
                    [
                        'request_id'    => $documentRequest->id,
                        'request_code'  => $documentRequest->request_code,
                        'document_name' => $documentRequest->document_name,
                        'admin_message' => $message,
                        'link'          => '/document-requests',
                    ]
                );
            }
        });

        $documentRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory']);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_DOC_REQ_UNAVAILABLE,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Admin marked document request ' . $documentRequest->request_code . ' (' . $documentRequest->document_name . ') as unavailable.',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
        );

        return response()->json([
            'message' => 'Request marked as unavailable.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Cancel a document request (admin side).
     */
    public function cancel(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        if (in_array($documentRequest->status, [
            DocumentRequest::STATUS_FULFILLED,
            DocumentRequest::STATUS_CANCELLED,
        ], true)) {
            return response()->json([
                'message' => 'This request cannot be cancelled in its current status.',
            ], 422);
        }

        $admin = $request->user('admin');

        $documentRequest->update([
            'status'       => DocumentRequest::STATUS_CANCELLED,
            'admin_id'     => $admin->id,
            'cancelled_at' => Carbon::now(),
        ]);

        $documentRequest->load(['teacher:id,teacher_id,first_name,middle_name,last_name,suffix,username,position,class_advisory']);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_DOC_REQ_CANCELLED,
            category: AuditLog::CAT_DOC_REQUESTS,
            description: 'Admin cancelled document request ' . $documentRequest->request_code . ' (' . $documentRequest->document_name . ').',
            subject: $documentRequest,
            subjectLabel: $documentRequest->request_code,
        );

        return response()->json([
            'message' => 'Request cancelled successfully.',
            'request' => $this->formatRequest($documentRequest),
        ]);
    }

    /**
     * Download the fulfilled document file.
     */
    public function download(DocumentRequest $documentRequest): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $filePath     = $documentRequest->file_path;
        $originalName = $documentRequest->file_original_name;

        if (! is_string($filePath) || $filePath === '' || ! Storage::disk('public')->exists($filePath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return response()->download(
            Storage::disk('public')->path($filePath),
            is_string($originalName) && $originalName !== '' ? $originalName : basename($filePath)
        );
    }

    private function formatRequest(DocumentRequest $item): array
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
            'id'               => $item->id,
            'request_code'     => $item->request_code,
            'teacher'          => $teacher ? [
                'id'             => $teacher->id,
                'teacher_id'     => $teacher->teacher_id,
                'name'           => $teacherName,
                'username'       => $teacher->username,
                'position'       => $teacher->position,
                'class_advisory' => $teacher->class_advisory,
            ] : null,
            'document_code'    => $item->document_code,
            'document_name'    => $item->document_name,
            'purpose'          => $item->purpose,
            'status'           => $item->status,
            'admin_message'    => $item->admin_message,
            'request_date'     => $item->created_at?->toDateString(),
            'acknowledged_at'  => $item->acknowledged_at?->toIso8601String(),
            'fulfilled_at'     => $item->fulfilled_at?->toIso8601String(),
            'cancelled_at'     => $item->cancelled_at?->toIso8601String(),
            'created_at'       => $item->created_at?->toIso8601String(),
            // File info (only when fulfilled)
            'file' => $item->file_path ? [
                'original_name'  => $item->file_original_name,
                'file_url'       => asset('storage/' . ltrim($item->file_path, '/')),
                'formatted_size' => $item->formatted_size,
                'mime_type'      => $item->file_mime_type,
            ] : null,
        ];
    }
}
