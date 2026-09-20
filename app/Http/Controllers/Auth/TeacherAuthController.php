<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin\Teacher;
use App\Models\Admin\TeacherActivationCode;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TeacherAuthController extends Controller
{
    /**
     * Look up a pending teacher by their teacher_id.
     * Returns only the name — used for auto-fill on the activation form.
     */
    public function lookup(string $teacherId): JsonResponse
    {
        $teacher = Teacher::where('teacher_id', $teacherId)
            ->where('status', 'pending')
            ->first();

        if (! $teacher) {
            return response()->json([
                'message' => 'No pending account found for this Teacher ID.',
            ], 404);
        }

        return response()->json([
            'first_name' => $teacher->first_name,
            'last_name'  => $teacher->last_name,
        ], 200);
    }

    /**
     * Activate a teacher account using the one-time activation code.
     * Sets the password and marks the account as active.
     */
    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teacherId'        => 'required|string',
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

        // Find the teacher by teacher_id
        $teacher = Teacher::where('teacher_id', $request->teacherId)->first();

        if (! $teacher) {
            return response()->json([
                'message' => 'No teacher found with that ID.',
            ], 404);
        }

        // Verify first and last name match
        if (
            strtolower($teacher->first_name) !== strtolower($request->firstName) ||
            strtolower($teacher->last_name)  !== strtolower($request->lastName)
        ) {
            return response()->json([
                'message' => 'The provided name does not match our records.',
            ], 422);
        }

        // Teacher must still be pending
        if ($teacher->status !== 'pending') {
            return response()->json([
                'message' => 'This account has already been activated.',
            ], 409);
        }

        // Find a matching unused activation code
        $codeHash = hash('sha256', $request->activationCode);

        $activationCode = TeacherActivationCode::where('teacher_id', $teacher->id)
            ->where('code_hash', $codeHash)
            ->where('status', 'unused')
            ->first();

        if (! $activationCode) {
            return response()->json([
                'message' => 'Invalid or already used activation code.',
            ], 422);
        }

        // Activate the account
        $teacher->update([
            'password'     => $request->password, // cast handles hashing
            'status'       => 'active',
            'activated_at' => now(),
        ]);

        $activationCode->update([
            'status'  => 'used',
            'used_at' => now(),
        ]);

        $token = $teacher->createToken('teacher-token')->plainTextToken;

        return response()->json([
            'message' => 'Account activated successfully.',
            'teacher' => $this->teacherResource($teacher),
            'token'   => $token,
        ], 200);
    }

    /**
     * Login with username and password.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid credentials',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $teacher = Teacher::where('username', $request->username)->first();

        if (! $teacher || ! Hash::check($request->password, $teacher->password)) {
            return response()->json([
                'message' => 'Invalid username or password.',
            ], 401);
        }

        if ($teacher->status !== 'active') {
            return response()->json([
                'message' => 'Your account is not yet activated. Please use your activation code first.',
            ], 403);
        }

        // Revoke existing tokens to keep only one active session
        $teacher->tokens()->delete();

        $token = $teacher->createToken('teacher-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'teacher' => $this->teacherResource($teacher),
            'token'   => $token,
        ], 200);
    }

    /**
     * Return the currently authenticated teacher.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'teacher' => $this->teacherResource($request->user('teacher')),
        ], 200);
    }

    /**
     * Revoke the current token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user('teacher')->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ], 200);
    }

    private function teacherResource(Teacher $teacher): array
    {
        return [
            'id'             => $teacher->id,
            'teacher_id'     => $teacher->teacher_id,
            'first_name'     => $teacher->first_name,
            'middle_name'    => $teacher->middle_name,
            'last_name'      => $teacher->last_name,
            'suffix'         => $teacher->suffix,
            'username'       => $teacher->username,
            'position'       => $teacher->position,
            'class_advisory' => $teacher->class_advisory,
            'status'         => $teacher->status,
            'activated_at'   => $teacher->activated_at,
        ];
    }
}
