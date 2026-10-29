<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin\Staff;
use App\Models\Admin\StaffActivationCode;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StaffAuthController extends Controller
{
    /**
     * Look up a pending staff by their staff_id.
     */
    public function lookup(string $staffId): JsonResponse
    {
        $staff = Staff::where('staff_id', $staffId)
            ->where('status', 'pending')
            ->first();

        if (! $staff) {
            return response()->json([
                'message' => 'No pending account found for this Staff ID.',
            ], 404);
        }

        return response()->json([
            'first_name' => $staff->first_name,
            'last_name'  => $staff->last_name,
        ], 200);
    }

    /**
     * Activate a staff account using the one-time activation code.
     */
    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'staffId'          => 'required|string',
            'firstName'        => 'required|string',
            'lastName'         => 'required|string',
            'activationCode'   => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $staff = Staff::where('staff_id', $request->staffId)->first();

        if (! $staff) {
            return response()->json([
                'message' => 'No staff found with that ID.',
            ], 404);
        }

        if (
            strtolower($staff->first_name) !== strtolower($request->firstName) ||
            strtolower($staff->last_name)  !== strtolower($request->lastName)
        ) {
            return response()->json([
                'message' => 'The provided name does not match our records.',
            ], 422);
        }

        if ($staff->status !== 'pending') {
            return response()->json([
                'message' => 'This account has already been activated.',
            ], 409);
        }

        $codeHash = hash('sha256', $request->activationCode);

        $activationCode = StaffActivationCode::where('staff_id', $staff->id)
            ->where('code_hash', $codeHash)
            ->where('status', 'unused')
            ->first();

        if (! $activationCode) {
            return response()->json([
                'message' => 'Invalid or already used activation code.',
            ], 422);
        }

        $staff->update([
            'password'     => $request->password,
            'status'       => 'active',
            'activated_at' => now(),
        ]);

        $activationCode->update([
            'status'  => 'used',
            'used_at' => now(),
        ]);

        AuditLogService::log(
            actor: $staff,
            role: 'staff',
            action: 'staff.activated',
            category: AuditLog::CAT_AUTH,
            description: 'Staff account "' . $staff->username . '" activated successfully.',
            subject: $staff,
            subjectLabel: $staff->staff_id,
        );

        return response()->json([
            'message' => 'Account activated successfully. You may now log in.',
        ], 200);
    }

    /**
     * Authenticate a staff user.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $staff = Staff::where('username', $request->username)->first();

        if (! $staff || ! $staff->password || ! Hash::check($request->password, $staff->password)) {
            AuditLogService::log(
                actor: null,
                role: 'staff',
                action: 'staff.login.failed',
                category: AuditLog::CAT_AUTH,
                description: 'Failed login attempt for staff username "' . $request->username . '".',
                status: AuditLog::STATUS_FAILED,
            );

            return response()->json([
                'message' => 'Invalid username or password.',
            ], 401);
        }

        if ($staff->status !== 'active') {
            return response()->json([
                'message' => 'Your account is not yet activated. Please use your activation code first.',
            ], 403);
        }

        $staff->tokens()->delete();

        $token = $staff->createToken('staff-token')->plainTextToken;

        AuditLogService::log(
            actor: $staff,
            role: 'staff',
            action: 'staff.login',
            category: AuditLog::CAT_AUTH,
            description: 'Staff "' . $staff->username . '" logged in successfully.',
        );

        return response()->json([
            'message' => 'Login successful.',
            'staff'   => $this->staffResource($staff),
            'token'   => $token,
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'staff' => $this->staffResource($request->user('staff')),
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $staff = $request->user('staff');

        AuditLogService::log(
            actor: $staff,
            role: 'staff',
            action: 'staff.logout',
            category: AuditLog::CAT_AUTH,
            description: 'Staff "' . $staff->username . '" logged out.',
        );

        $staff->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ], 200);
    }

    private function staffResource(Staff $staff): array
    {
        return [
            'id'           => $staff->id,
            'staff_id'     => $staff->staff_id,
            'first_name'   => $staff->first_name,
            'middle_name'  => $staff->middle_name,
            'last_name'    => $staff->last_name,
            'suffix'       => $staff->suffix,
            'username'     => $staff->username,
            'position'     => $staff->position,
            'status'       => $staff->status,
            'activated_at' => $staff->activated_at,
        ];
    }
}