<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Admin\Staff;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');

        $validator = Validator::make($request->all(), [
            'firstName'  => 'required|string|max:100',
            'middleName' => 'nullable|string|max:100',
            'lastName'   => 'required|string|max:100',
            'suffix'     => 'nullable|in:none,Jr.,Sr.,II,III,IV,V',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $staff->update([
            'first_name'  => $data['firstName'],
            'middle_name' => $data['middleName'] ?? null,
            'last_name'   => $data['lastName'],
            'suffix'      => ($data['suffix'] ?? 'none') === 'none' ? null : $data['suffix'],
        ]);

        AuditLogService::log(
            actor: $staff,
            role: AuditLog::ROLE_STAFF,
            action: AuditLog::ACT_STAFF_PROFILE_UPDATED,
            category: AuditLog::CAT_USER_MGMT,
            description: 'Staff "' . $staff->username . '" updated their profile.',
            subject: $staff,
            subjectLabel: $staff->staff_id,
        );

        return response()->json([
            'message' => 'Profile updated successfully.',
            'staff'   => $this->staffResource($staff),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');

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

        $file = $request->file('avatar');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = 'staff_' . $staff->id . '_' . Str::uuid() . '.' . $extension;
        $path = $file->storeAs('profile-pictures/staffs', $filename, 'public');

        if ($staff->profile_picture && Storage::disk('public')->exists($staff->profile_picture)) {
            Storage::disk('public')->delete($staff->profile_picture);
        }

        $staff->update(['profile_picture' => $path]);

        AuditLogService::log(
            actor: $staff,
            role: AuditLog::ROLE_STAFF,
            action: AuditLog::ACT_STAFF_AVATAR_UPDATED,
            category: AuditLog::CAT_USER_MGMT,
            description: 'Staff "' . $staff->username . '" updated their profile picture.',
            subject: $staff,
            subjectLabel: $staff->staff_id,
        );

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'staff'   => $this->staffResource($staff->fresh()),
        ]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');

        if ($staff->profile_picture) {
            if (Storage::disk('public')->exists($staff->profile_picture)) {
                Storage::disk('public')->delete($staff->profile_picture);
            }
            $staff->update(['profile_picture' => null]);

            AuditLogService::log(
                actor: $staff,
                role: AuditLog::ROLE_STAFF,
                action: AuditLog::ACT_STAFF_AVATAR_REMOVED,
                category: AuditLog::CAT_USER_MGMT,
                description: 'Staff "' . $staff->username . '" removed their profile picture.',
                subject: $staff,
                subjectLabel: $staff->staff_id,
            );
        }

        return response()->json([
            'message' => 'Profile picture removed successfully.',
            'staff'   => $this->staffResource($staff->fresh()),
        ]);
    }

    private function staffResource(Staff $staff): array
    {
        return [
            'id'              => $staff->id,
            'staff_id'        => $staff->staff_id,
            'first_name'      => $staff->first_name,
            'middle_name'     => $staff->middle_name,
            'last_name'       => $staff->last_name,
            'suffix'          => $staff->suffix,
            'username'        => $staff->username,
            'position'        => $staff->position,
            'profile_picture' => $staff->profile_picture
                ? Storage::disk('public')->url($staff->profile_picture)
                : null,
            'status'          => $staff->status,
            'activated_at'    => $staff->activated_at,
        ];
    }
}
