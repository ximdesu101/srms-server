<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentRequestController as AdminDocumentRequestController;
use App\Http\Controllers\Admin\DocumentSubmissionController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\SchoolFormTemplateController;
use App\Http\Controllers\Admin\SubmissionRequestController as AdminSubmissionRequestController;
use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\TeacherAuthController;
use App\Http\Controllers\Teacher\DocumentRequestController as TeacherDocumentRequestController;
use App\Http\Controllers\Teacher\NotificationController as TeacherNotificationController;
use App\Http\Controllers\Teacher\SubmissionRequestController as TeacherSubmissionRequestController;
use App\Http\Controllers\Teacher\TemplateController as TeacherTemplateController;
use Illuminate\Support\Facades\Route;

/* ======================== Admin Routes ======================== */

Route::prefix('admin')->group(function () {
    // Public
    Route::post('/login', [AdminAuthController::class, 'login']);

    // Protected
    Route::middleware('auth:admin')->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
    });
});

// Admin audit logs
Route::prefix('admin/audit-logs')->middleware('auth:admin')->group(function () {
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('/meta', [AuditLogController::class, 'meta']);
});

// Admin dashboard aggregated endpoints
Route::prefix('admin/dashboard')->middleware('auth:admin,staff')->group(function () {
    Route::get('/metrics', [DashboardController::class, 'metrics']);
    Route::get('/monthly-stats', [DashboardController::class, 'monthlyStats']);
    Route::get('/calendar-events', [DashboardController::class, 'calendarEvents']);
});

// Admin-managed teacher accounts
Route::prefix('admin/teachers')->middleware('auth:admin')->group(function () {
    Route::get('/metrics', [TeacherController::class, 'metrics']);
    Route::get('/', [TeacherController::class, 'index']);
    Route::post('/', [TeacherController::class, 'store']);
    Route::put('/{teacher}', [TeacherController::class, 'update']);
    Route::delete('/{teacher}', [TeacherController::class, 'destroy']);
});

// School form templates (official Excel templates per SF1–SF10)
Route::prefix('admin/school-forms')->middleware('auth:admin,staff')->group(function () {
    Route::get('/summary', [SchoolFormTemplateController::class, 'summary']);
    Route::get('/{formCode}/templates', [SchoolFormTemplateController::class, 'index']);
    Route::get('/{formCode}/template', [SchoolFormTemplateController::class, 'show']);
    Route::post('/{formCode}/template', [SchoolFormTemplateController::class, 'store']);
    Route::post('/{formCode}/templates/{id}/activate', [SchoolFormTemplateController::class, 'activate']);
    Route::delete('/{formCode}/templates/{id}', [SchoolFormTemplateController::class, 'destroy']);
    Route::get('/{formCode}/template/download', [SchoolFormTemplateController::class, 'download']);
    Route::get('/{formCode}/template/preview', [SchoolFormTemplateController::class, 'preview']);
});

// Administrative form templates (reuse same controller + storage logic; distinct summary)
Route::prefix('admin/admin-forms')->middleware('auth:admin,staff')->group(function () {
    Route::get('/summary', [SchoolFormTemplateController::class, 'adminSummary']);
    // Template CRUD shares the same endpoints under school-forms/{formCode} for codes DTR, ACR, etc.
    Route::get('/{formCode}/templates', [SchoolFormTemplateController::class, 'index']);
    Route::get('/{formCode}/template', [SchoolFormTemplateController::class, 'show']);
    Route::post('/{formCode}/template', [SchoolFormTemplateController::class, 'store']);
    Route::post('/{formCode}/templates/{id}/activate', [SchoolFormTemplateController::class, 'activate']);
    Route::delete('/{formCode}/templates/{id}', [SchoolFormTemplateController::class, 'destroy']);
    Route::get('/{formCode}/template/download', [SchoolFormTemplateController::class, 'download']);
    Route::get('/{formCode}/template/preview', [SchoolFormTemplateController::class, 'preview']);
});

// Admin submission requests
Route::prefix('admin/submission-requests')->middleware('auth:admin,staff')->group(function () {
    Route::get('/metrics', [AdminSubmissionRequestController::class, 'metrics']);
    Route::get('/active-teachers', [AdminSubmissionRequestController::class, 'activeTeachers']);
    Route::get('/', [AdminSubmissionRequestController::class, 'index']);
    Route::post('/', [AdminSubmissionRequestController::class, 'store']);
    Route::get('/{submissionRequest}', [AdminSubmissionRequestController::class, 'show']);
    Route::put('/{submissionRequest}', [AdminSubmissionRequestController::class, 'update']);
    Route::post('/{submissionRequest}/cancel', [AdminSubmissionRequestController::class, 'cancel']);
});

// Admin document submissions (approval)
Route::prefix('admin/document-submissions')->middleware('auth:admin,staff')->group(function () {
    Route::get('/metrics', [DocumentSubmissionController::class, 'metrics']);
    Route::get('/', [DocumentSubmissionController::class, 'index']);
    Route::get('/{documentSubmission}', [DocumentSubmissionController::class, 'show']);
    Route::post('/{documentSubmission}/approve', [DocumentSubmissionController::class, 'approve']);
    Route::post('/{documentSubmission}/request-revision', [DocumentSubmissionController::class, 'requestRevision']);
    Route::get('/{documentSubmission}/download', [DocumentSubmissionController::class, 'download']);
});

/* ======================== Teacher Routes ======================== */

Route::prefix('teacher')->group(function () {
    // Public
    Route::get('/lookup/{teacherId}', [TeacherAuthController::class, 'lookup']);
    Route::post('/activate', [TeacherAuthController::class, 'activate']);
    Route::post('/login', [TeacherAuthController::class, 'login']);

    // Protected
    Route::middleware('auth:teacher')->group(function () {
        Route::get('/me', [TeacherAuthController::class, 'me']);
        Route::post('/logout', [TeacherAuthController::class, 'logout']);

        // Teacher submission requests
        Route::get('/submission-requests', [TeacherSubmissionRequestController::class, 'index']);
        Route::get('/submission-requests/{submissionRequest}', [TeacherSubmissionRequestController::class, 'show']);
        Route::post('/submission-requests/{submissionRequest}/acknowledge', [TeacherSubmissionRequestController::class, 'acknowledge']);
        Route::post('/submission-requests/{submissionRequest}/submit', [TeacherSubmissionRequestController::class, 'submit']);
        Route::post('/document-submissions/{documentSubmission}/resubmit', [TeacherSubmissionRequestController::class, 'resubmit']);

        // Notifications
        Route::get('/notifications', [TeacherNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [TeacherNotificationController::class, 'unreadCount']);
        Route::post('/notifications/{notification}/read', [TeacherNotificationController::class, 'markAsRead']);
        Route::post('/notifications/mark-all-read', [TeacherNotificationController::class, 'markAllAsRead']);

        // Templates (read-only — download only)
        Route::get('/templates/school-forms', [TeacherTemplateController::class, 'schoolFormsSummary']);
        Route::get('/templates/admin-forms', [TeacherTemplateController::class, 'adminFormsSummary']);
        Route::get('/templates/{formCode}', [TeacherTemplateController::class, 'show']);
        Route::get('/templates/{formCode}/download', [TeacherTemplateController::class, 'download']);
    });
});

// Admin document requests (teacher-initiated requests for documents)
Route::prefix('admin/document-requests')->middleware('auth:admin,staff')->group(function () {
    Route::get('/metrics', [AdminDocumentRequestController::class, 'metrics']);
    Route::get('/', [AdminDocumentRequestController::class, 'index']);
    Route::get('/{documentRequest}', [AdminDocumentRequestController::class, 'show']);
    Route::post('/{documentRequest}/acknowledge', [AdminDocumentRequestController::class, 'acknowledge']);
    Route::post('/{documentRequest}/processing', [AdminDocumentRequestController::class, 'markProcessing']);
    Route::post('/{documentRequest}/fulfill', [AdminDocumentRequestController::class, 'fulfill']);
    Route::post('/{documentRequest}/unavailable', [AdminDocumentRequestController::class, 'markUnavailable']);
    Route::post('/{documentRequest}/cancel', [AdminDocumentRequestController::class, 'cancel']);
    Route::get('/{documentRequest}/download', [AdminDocumentRequestController::class, 'download']);
});

// Admin notifications
Route::prefix('admin/notifications')->middleware('auth:admin,staff')->group(function () {
    Route::get('/', [AdminNotificationController::class, 'index']);
    Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount']);
    Route::post('/{notification}/read', [AdminNotificationController::class, 'markAsRead']);
    Route::post('/mark-all-read', [AdminNotificationController::class, 'markAllAsRead']);
});

// Teacher document requests (teacher submits requests to admin for documents)
Route::prefix('teacher/document-requests')->middleware('auth:teacher')->group(function () {
    Route::get('/drafts', [TeacherDocumentRequestController::class, 'drafts']);
    Route::get('/', [TeacherDocumentRequestController::class, 'index']);
    Route::post('/', [TeacherDocumentRequestController::class, 'store']);
    Route::get('/{documentRequest}', [TeacherDocumentRequestController::class, 'show']);
    Route::post('/{documentRequest}/cancel', [TeacherDocumentRequestController::class, 'cancel']);
    Route::post('/{documentRequest}/submit-draft', [TeacherDocumentRequestController::class, 'submitDraft']);
    Route::delete('/{documentRequest}/draft', [TeacherDocumentRequestController::class, 'deleteDraft']);
});

/* ======================== Staff Routes ======================== */

Route::prefix('staff')->group(function () {
    // Public
    Route::post('/login', [\App\Http\Controllers\Auth\StaffAuthController::class, 'login']);
    Route::get('/lookup/{staffId}', [\App\Http\Controllers\Auth\StaffAuthController::class, 'lookup']);
    Route::post('/activate', [\App\Http\Controllers\Auth\StaffAuthController::class, 'activate']);

    // Protected
    Route::middleware('auth:staff')->group(function () {
        Route::get('/me', [\App\Http\Controllers\Auth\StaffAuthController::class, 'me']);
        Route::post('/logout', [\App\Http\Controllers\Auth\StaffAuthController::class, 'logout']);
    });
});

// Admin-managed staff accounts
Route::prefix('admin/staffs')->middleware('auth:admin')->group(function () {
    Route::get('/metrics', [\App\Http\Controllers\Admin\StaffController::class, 'metrics']);
    Route::get('/', [\App\Http\Controllers\Admin\StaffController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Admin\StaffController::class, 'store']);
    Route::put('/{staff}', [\App\Http\Controllers\Admin\StaffController::class, 'update']);
    Route::delete('/{staff}', [\App\Http\Controllers\Admin\StaffController::class, 'destroy']);
});