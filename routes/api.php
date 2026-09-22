<?php

use App\Http\Controllers\Admin\DocumentSubmissionController;
use App\Http\Controllers\Admin\SchoolFormTemplateController;
use App\Http\Controllers\Admin\SubmissionRequestController as AdminSubmissionRequestController;
use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\TeacherAuthController;
use App\Http\Controllers\Teacher\NotificationController as TeacherNotificationController;
use App\Http\Controllers\Teacher\SubmissionRequestController as TeacherSubmissionRequestController;
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

// Admin-managed teacher accounts
Route::prefix('admin/teachers')->middleware('auth:admin')->group(function () {
    Route::get('/metrics', [TeacherController::class, 'metrics']);
    Route::get('/', [TeacherController::class, 'index']);
    Route::post('/', [TeacherController::class, 'store']);
    Route::put('/{teacher}', [TeacherController::class, 'update']);
    Route::delete('/{teacher}', [TeacherController::class, 'destroy']);
});

// School form templates (official Excel templates per SF1–SF10)
Route::prefix('admin/school-forms')->middleware('auth:admin')->group(function () {
    Route::get('/{formCode}/template', [SchoolFormTemplateController::class, 'show']);
    Route::post('/{formCode}/template', [SchoolFormTemplateController::class, 'store']);
    Route::get('/{formCode}/template/download', [SchoolFormTemplateController::class, 'download']);
    Route::get('/{formCode}/template/preview', [SchoolFormTemplateController::class, 'preview']);
});

// Admin submission requests
Route::prefix('admin/submission-requests')->middleware('auth:admin')->group(function () {
    Route::get('/metrics', [AdminSubmissionRequestController::class, 'metrics']);
    Route::get('/active-teachers', [AdminSubmissionRequestController::class, 'activeTeachers']);
    Route::get('/', [AdminSubmissionRequestController::class, 'index']);
    Route::post('/', [AdminSubmissionRequestController::class, 'store']);
    Route::get('/{submissionRequest}', [AdminSubmissionRequestController::class, 'show']);
    Route::post('/{submissionRequest}/cancel', [AdminSubmissionRequestController::class, 'cancel']);
});

// Admin document submissions (approval)
Route::prefix('admin/document-submissions')->middleware('auth:admin')->group(function () {
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
    });
});