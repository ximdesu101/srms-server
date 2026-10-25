<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin\DocumentRequest;
use App\Models\Notification;
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
     */
    public function store(Request $request): JsonResponse
    {
        $teacher = $request->user('teacher');

        $validator = Validator::make($request->all(), [
            'documentCode' => ['required', 'string', Rule::in(array_keys(DocumentRequest::DOCUMENT_MAP))],
            'purpose'      => 'nullable|string|max:500',
        ]);

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

                // Notify all admins — since there is typically one admin, we notify Admin ID 1
                // or use a query if multiple admins exist
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
                            'link'          => '/document-requests/' . $dr->id,
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

        return response()->json([
            'message' => 'Request cancelled successfully.',
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
            // File info — only populated when status is Fulfilled
            'file' => $item->file_path ? [
                'original_name'  => $item->file_original_name,
                'file_url'       => asset('storage/' . ltrim($item->file_path, '/')),
                'formatted_size' => $item->formatted_size,
                'mime_type'      => $item->file_mime_type,
            ] : null,
        ];
    }
}
