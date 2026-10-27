<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin\Admin;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid credentials',
                'errors' => $validator->errors(),
            ], 401);
        }

        $admin = Admin::where('username', $request->username)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            // Log failed login attempt (actor unknown — store username in metadata)
            AuditLogService::log(
                actor: null,
                role: AuditLog::ROLE_ADMIN,
                action: AuditLog::ACT_ADMIN_LOGIN_FAILED,
                category: AuditLog::CAT_AUTH,
                description: 'Failed admin login attempt for username "' . $request->username . '".',
                status: AuditLog::STATUS_FAILED,
                metadata: ['attempted_username' => $request->username],
            );

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $admin->createToken('admin-token')->plainTextToken;

        AuditLogService::log(
            actor: $admin,
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_ADMIN_LOGIN,
            category: AuditLog::CAT_AUTH,
            description: 'Admin "' . $admin->username . '" logged in successfully.',
        );

        return response()->json([
            'message' => 'Login successful',
            'admin' => [
                'id' => $admin->id,
                'username' => $admin->username,
            ],
            'token' => $token,
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'username' => $admin->username,
            ],
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        // Log before deleting the token so the actor is still resolvable
        AuditLogService::log(
            actor: $admin,
            role: AuditLog::ROLE_ADMIN,
            action: AuditLog::ACT_ADMIN_LOGOUT,
            category: AuditLog::CAT_AUTH,
            description: 'Admin "' . $admin->username . '" logged out.',
        );

        $admin->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful',
        ], 200);
    }
}
