<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Return a paginated list of audit log entries.
     *
     * Query parameters:
     *   role      string   'Admin' | 'Teacher' | 'All' (default 'All')
     *   search    string   Searches actor_name (username / full name)
     *   category  string   Filter by category label
     *   status    string   'success' | 'failed' | 'All'
     *   per_page  int      Rows per page (default 20, max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $role     = $request->query('role',     'All');
        $search   = $request->query('search',   '');
        $category = $request->query('category', '');
        $status   = $request->query('status',   'All');
        $perPage  = min((int) ($request->query('per_page', 20)), 100);

        $query = AuditLog::query()->orderByDesc('created_at');

        // ── Role / actor tab filter ───────────────────────────────────────────
        if ($role !== 'All' && in_array($role, [AuditLog::ROLE_ADMIN, AuditLog::ROLE_TEACHER], true)) {
            $query->where('actor_role', $role);
        }

        // ── Category filter ───────────────────────────────────────────────────
        if ($category !== '' && $category !== 'All') {
            $query->where('category', $category);
        }

        // ── Status filter ─────────────────────────────────────────────────────
        if ($status !== 'All' && in_array($status, [AuditLog::STATUS_SUCCESS, AuditLog::STATUS_FAILED], true)) {
            $query->where('status', $status);
        }

        // ── Search by actor name / username ───────────────────────────────────
        if ($search !== '') {
            $query->where('actor_name', 'like', '%' . $search . '%');
        }

        $logs = $query->paginate($perPage);

        // Format each entry for the API response
        $logs->getCollection()->transform(fn (AuditLog $log) => $this->format($log));

        return response()->json($logs);
    }

    /**
     * Available filter options (distinct categories present in the log).
     */
    public function meta(): JsonResponse
    {
        $categories = AuditLog::query()
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->filter()
            ->values();

        return response()->json([
            'categories' => $categories,
            'roles'      => [AuditLog::ROLE_ADMIN, AuditLog::ROLE_TEACHER],
            'statuses'   => [AuditLog::STATUS_SUCCESS, AuditLog::STATUS_FAILED],
        ]);
    }

    private function format(AuditLog $log): array
    {
        return [
            'id'            => $log->id,
            'actor_name'    => $log->actor_name,
            'actor_role'    => $log->actor_role,
            'action'        => $log->action,
            'category'      => $log->category,
            'description'   => $log->description,
            'subject_label' => $log->subject_label,
            'status'        => $log->status,
            'metadata'      => $log->metadata,
            'created_at'    => $log->created_at?->toIso8601String(),
        ];
    }
}
