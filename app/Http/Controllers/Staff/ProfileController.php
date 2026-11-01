<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Update the authenticated staff member's own personal information.
     * Sensitive fields (username, password, status, role, staff_id) are not
     * modifiable through this endpoint.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var \App\Models\Admin\Staff $staff */
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
            'staff' => [
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
            ],
        ]);
    }
}
