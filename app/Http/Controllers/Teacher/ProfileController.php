<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

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
            'teacher' => [
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
                'status'            => $teacher->status,
                'activated_at'      => $teacher->activated_at,
            ],
        ]);
    }
}
