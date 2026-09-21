<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin\SubmissionRequest;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionRequestController extends Controller
{
    /**
     * List submission requests assigned to the authenticated teacher.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user('teacher');
        $status = $request->query('status');

        $query = SubmissionRequest::query()
            ->where('teacher_id', $teacher->id)
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

    /**
     * Acknowledge a request (Requested -> Acknowledged).
     */
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

    /**
     * Mark request as submitted (Acknowledged/Requested -> Submitted).
     * Actual file upload is out of scope; this records the submission event.
     */
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

        $submissionRequest->status = SubmissionRequest::STATUS_SUBMITTED;
        $submissionRequest->submitted_at = Carbon::now();
        if (! $submissionRequest->acknowledged_at) {
            $submissionRequest->acknowledged_at = Carbon::now();
        }
        $submissionRequest->save();

        return response()->json([
            'message' => 'Document submitted successfully.',
            'request' => $this->formatRequest($submissionRequest),
        ]);
    }

    private function formatRequest(SubmissionRequest $item): array
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
        ];
    }
}