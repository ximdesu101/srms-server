<?php

use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\TeacherAuthController;
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
    });
});
