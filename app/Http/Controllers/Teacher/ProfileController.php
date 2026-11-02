<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin\Teacher;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    /**
     * Update the authenticated teacher's own personal / contact information.
     * Sensitive fields (username, password, status, role, teacher_id) are not
     * modifiable through this endpoint.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var \App\Models\Admin\Teacher $teacher */
        $teacher = $request->user('teacher');

        $validator = Validator::make($request->all(), [
            'firstName'        => 'required|string|max:100',
            'middleName'       => 'nullable|string|max:100',
            'lastName'         => 'required|string|max:100',
            'suffix'           => 'nullable|in:none,Jr.,Sr.,II,III,IV,V',
            'region'           => 'required|string|max:150',
            'province'         => 'required|string|max:150',
            'cityMunicipality' => 'required|string|max:150',
            'barangay'         => 'required|string|max:150',
            'contactNumber'    => ['required', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)]+$/'],
        ], [
            'contactNumber.regex' => 'Contact number may only contain digits, spaces, +, -, and parentheses.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $teacher->update([
            'first_name'        => $data['firstName'],
            'middle_name'       => $data['middleName'] ?? null,
            'last_name'         => $data['lastName'],
            'suffix'            => ($data['suffix'] ?? 'none') === 'none' ? null : $data['suffix'],
            'region'            => $data['region'],
            'province'          => $data['province'],
            'city_municipality' => $data['cityMunicipality'],
            'barangay'          => $data['barangay'],
            'contact_number'    => $data['contactNumber'],
        ]);

        AuditLogService::log(
            actor: $teacher,
            role: AuditLog::ROLE_TEACHER,
            action: AuditLog::ACT_TEACHER_PROFILE_UPDATED,
            category: AuditLog::CAT_USER_MGMT,
            description: 'Teacher "' . $teacher->username . '" updated their profile.',
            subject: $teacher,
            subjectLabel: $teacher->teacher_id,
        );

        return response()->json([
            'message' => 'Profile updated successfully.',
            'teacher' => $this->teacherResource($teacher),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var Teacher $teacher */
        $teacher = $request->user('teacher');

        $validator = Validator::make($request->all(), [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp,gif',
                'max:2048',
            ],
        ], [
            'avatar.required' => 'Please select an image to upload.',
            'avatar.image'    => 'The file must be an image.',
            'avatar.mimes'    => 'Only JPEG, PNG, WebP, and GIF images are allowed.',
            'avatar.max'      => 'Image size must not exceed 2 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $file      = $request->file('avatar');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename  = 'teacher_' . $teacher->id . '_' . Str::uuid() . '.' . $extension;
        $path      = $file->storeAs('profile-pictures/teachers', $filename, 'public');

        // Delete the old avatar if one exists
        if ($teacher->profile_picture && Storage::disk('public')->exists($teacher->profile_picture)) {
            Storage::disk('public')->delete($teacher->profile_picture);
        }

        $teacher->update(['profile_picture' => $path]);

        AuditLogService::log(
            actor: $teacher,
            role: AuditLog::ROLE_TEACHER,
            action: AuditLog::ACT_TEACHER_AVATAR_UPDATED,
            category: AuditLog::CAT_USER_MGMT,
            description: 'Teacher "' . $teacher->username . '" updated their profile picture.',
            subject: $teacher,
            subjectLabel: $teacher->teacher_id,
        );

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'teacher' => $this->teacherResource($teacher->fresh()),
        ]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        /** @var Teacher $teacher */
        $teacher = $request->user('teacher');

        if ($teacher->profile_picture) {
            if (Storage::disk('public')->exists($teacher->profile_picture)) {
                Storage::disk('public')->delete($teacher->profile_picture);
            }
            $teacher->update(['profile_picture' => null]);

            AuditLogService::log(
                actor: $teacher,
                role: AuditLog::ROLE_TEACHER,
                action: AuditLog::ACT_TEACHER_AVATAR_REMOVED,
                category: AuditLog::CAT_USER_MGMT,
                description: 'Teacher "' . $teacher->username . '" removed their profile picture.',
                subject: $teacher,
                subjectLabel: $teacher->teacher_id,
            );
        }

        return response()->json([
            'message' => 'Profile picture removed successfully.',
            'teacher' => $this->teacherResource($teacher->fresh()),
        ]);
    }

    private function teacherResource(Teacher $teacher): array
    {
        return [
            'id'                => $teacher->id,
            'teacher_id'        => $teacher->teacher_id,
            'first_name'        => $teacher->first_name,
            'middle_name'       => $teacher->middle_name,
            'last_name'         => $teacher->last_name,
            'suffix'            => $teacher->suffix,
            'username'          => $teacher->username,
            'position'          => $teacher->position,
            'class_advisory'    => $teacher->class_advisory,
            'region'            => $teacher->region,
            'province'          => $teacher->province,
            'city_municipality' => $teacher->city_municipality,
            'barangay'          => $teacher->barangay,
            'contact_number'    => $teacher->contact_number,
            'profile_picture'   => $teacher->profile_picture
                ? Storage::disk('public')->url($teacher->profile_picture)
                : null,
            'status'            => $teacher->status,
            'activated_at'      => $teacher->activated_at,
        ];
    }
}
