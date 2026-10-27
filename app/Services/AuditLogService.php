<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * Record an audit event.
     *
     * @param  Model|null  $actor       The user who performed the action (Admin or Teacher). Null for unauthenticated events.
     * @param  string      $role        AuditLog::ROLE_ADMIN or AuditLog::ROLE_TEACHER
     * @param  string      $action      Machine-readable action constant, e.g. AuditLog::ACT_ADMIN_LOGIN
     * @param  string      $category    Human-readable category, e.g. AuditLog::CAT_AUTH
     * @param  string      $description Human-readable sentence describing what happened
     * @param  Model|null  $subject     The record that was affected (optional)
     * @param  string|null $subjectLabel  Short label for the subject, e.g. 'DR-2026-001' (optional)
     * @param  string      $status      AuditLog::STATUS_SUCCESS or AuditLog::STATUS_FAILED
     * @param  array|null  $metadata    Extra context — NEVER include passwords or tokens
     */
    public static function log(
        ?Model $actor,
        string $role,
        string $action,
        string $category,
        string $description,
        ?Model $subject      = null,
        ?string $subjectLabel = null,
        string $status       = AuditLog::STATUS_SUCCESS,
        ?array $metadata     = null
    ): AuditLog {
        // Derive a display-friendly name from the actor model
        $actorName = null;
        if ($actor) {
            // Admin: use username; Teacher: use full_name attribute or username
            if (method_exists($actor, 'getFullNameAttribute')) {
                $actorName = $actor->getFullNameAttribute();
            } elseif (isset($actor->username)) {
                $actorName = $actor->username;
            }
        }

        return AuditLog::create([
            'actor_type'    => $actor ? get_class($actor) : null,
            'actor_id'      => $actor ? $actor->id : null,
            'actor_name'    => $actorName,
            'actor_role'    => $role,
            'action'        => $action,
            'category'      => $category,
            'description'   => $description,
            'subject_type'  => $subject ? get_class($subject) : null,
            'subject_id'    => $subject ? $subject->id : null,
            'subject_label' => $subjectLabel,
            'status'        => $status,
            'metadata'      => $metadata,
        ]);
    }
}
