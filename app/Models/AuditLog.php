<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    // ── Actor roles ──────────────────────────────────────────────────────────
    public const ROLE_ADMIN   = 'Admin';
    public const ROLE_TEACHER = 'Teacher';

    // ── Categories ───────────────────────────────────────────────────────────
    public const CAT_AUTH           = 'Authentication';
    public const CAT_USER_MGMT      = 'User Management';
    public const CAT_DOC_REQUESTS   = 'Document Requests';
    public const CAT_SUBMISSIONS     = 'Submission Requests';
    public const CAT_SCHOOL_FORMS   = 'School Forms';
    public const CAT_SYSTEM         = 'System';

    // ── Actions ──────────────────────────────────────────────────────────────
    // Authentication
    public const ACT_ADMIN_LOGIN         = 'admin.login';
    public const ACT_ADMIN_LOGIN_FAILED  = 'admin.login.failed';
    public const ACT_ADMIN_LOGOUT        = 'admin.logout';
    public const ACT_TEACHER_ACTIVATED   = 'teacher.activated';
    public const ACT_TEACHER_LOGIN       = 'teacher.login';
    public const ACT_TEACHER_LOGIN_FAILED = 'teacher.login.failed';
    public const ACT_TEACHER_LOGOUT      = 'teacher.logout';

    // Staff auth
    public const ROLE_STAFF               = 'Staff';
    public const ACT_STAFF_LOGIN          = 'staff.login';
    public const ACT_STAFF_LOGIN_FAILED   = 'staff.login.failed';
    public const ACT_STAFF_LOGOUT         = 'staff.logout';
    public const ACT_STAFF_ACTIVATED      = 'staff.activated';

    // User management (admin)
    public const ACT_TEACHER_CREATED      = 'teacher.created';
    public const ACT_TEACHER_UPDATED      = 'teacher.updated';
    public const ACT_TEACHER_DELETED      = 'teacher.deleted';
    public const ACT_TEACHER_DEACTIVATED  = 'teacher.deactivated';
    public const ACT_TEACHER_REACTIVATED  = 'teacher.reactivated';
    public const ACT_STAFF_CREATED        = 'staff.created';
    public const ACT_STAFF_UPDATED        = 'staff.updated';
    public const ACT_STAFF_DELETED        = 'staff.deleted';
    public const ACT_STAFF_DEACTIVATED    = 'staff.deactivated';
    public const ACT_STAFF_REACTIVATED    = 'staff.reactivated';

    // Profile self-service (teacher / staff)
    public const ACT_TEACHER_PROFILE_UPDATED = 'teacher.profile_updated';
    public const ACT_STAFF_PROFILE_UPDATED   = 'staff.profile_updated';

    // Submission requests (admin → teacher)
    public const ACT_SUBMISSION_REQ_CREATED   = 'submission_request.created';
    public const ACT_SUBMISSION_REQ_SENT      = 'submission_request.sent';
    public const ACT_SUBMISSION_REQ_CANCELLED = 'submission_request.cancelled';
    public const ACT_SUBMISSION_APPROVED      = 'document_submission.approved';
    public const ACT_SUBMISSION_REVISION      = 'document_submission.revision_requested';

    // Teacher submission actions
    public const ACT_SUBMISSION_REQ_ACKNOWLEDGED = 'submission_request.acknowledged';
    public const ACT_SUBMISSION_REQ_SUBMITTED    = 'submission_request.submitted';
    public const ACT_SUBMISSION_RESUBMITTED      = 'document_submission.resubmitted';

    // Document requests (teacher → admin)
    public const ACT_DOC_REQ_CREATED      = 'document_request.created';
    public const ACT_DOC_REQ_ACKNOWLEDGED = 'document_request.acknowledged';
    public const ACT_DOC_REQ_PROCESSING   = 'document_request.processing';
    public const ACT_DOC_REQ_FULFILLED    = 'document_request.fulfilled';
    public const ACT_DOC_REQ_UNAVAILABLE  = 'document_request.unavailable';
    public const ACT_DOC_REQ_CANCELLED    = 'document_request.cancelled';

    // School forms
    public const ACT_FORM_UPLOADED    = 'school_form.uploaded';
    public const ACT_FORM_ACTIVATED   = 'school_form.activated';
    public const ACT_FORM_DELETED     = 'school_form.deleted';

    // ── Statuses ─────────────────────────────────────────────────────────────
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_role',
        'action',
        'category',
        'description',
        'subject_type',
        'subject_id',
        'subject_label',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** Polymorphic relation to the actor (Admin or Teacher). */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /** Polymorphic relation to the affected subject. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
