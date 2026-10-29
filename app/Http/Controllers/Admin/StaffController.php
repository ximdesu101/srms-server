<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Staff;
use App\Models\AuditLog;
use App\Services\ActivationCodeService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
    private const POSITIONS = [
        'Administrative Aide',
        'Administrative Assistant',
        'Clerk',
        'Records Officer',
        'Registrar',
        'Staff',
        'Office Staff',
    ];

    private const SUFFIXES = ['none', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

    public function store(Request $request, ActivationCodeService $activationCodes): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'staffId'    => 'required|string|max:50|unique:staffs,staff_id',
            'firstName'  => 'required|string|max:100',
            'middleName' => 'nullable|string|max:100',
            'lastName'   => 'required|string|max:100',
            'suffix'     => 'nullable|in:' . implode(',', self::SUFFIXES),
            'username'   => 'required|string|max:50|unique:staffs,username',
            'position'   => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $staff = Staff::create([
            'staff_id'    => $data['staffId'],
            'first_name'  => $data['firstName'],
            'middle_name' => $data['middleName'] ?? null,
            'last_name'   => $data['lastName'],
            'suffix'      => ($data['suffix'] ?? 'none') === 'none' ? null : $data['suffix'],
            'username'    => $data['username'],
            'position'    => $data['position'] ?? null,
            'status'      => 'pending',
        ]);

        $activationCode = $activationCodes->generateForStaff($staff->id);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: 'staff.created',
            category: AuditLog::CAT_USER_MGMT,
            description: 'Admin created staff account for "' . trim($staff->first_name . ' ' . $staff->last_name) . '" (' . $staff->staff_id . ').',
            subject: $staff,
            subjectLabel: $staff->staff_id,
        );

        return response()->json([
            'message' => 'Staff account created successfully. Provide the activation code to the staff member.',
            'staff' => [
                'id'         => $staff->id,
                'staff_id'   => $staff->staff_id,
                'first_name' => $staff->first_name,
                'middle_name'=> $staff->middle_name,
                'last_name'  => $staff->last_name,
                'suffix'     => $staff->suffix,
                'username'   => $staff->username,
                'position'   => $staff->position,
                'status'     => $staff->status,
            ],
            'activation_code' => $activationCode,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $query = Staff::query()->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('staff_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $staffs = $query->paginate(10);

        $staffs->getCollection()->transform(function (Staff $item) {
            return [
                'id'           => $item->id,
                'staff_id'     => $item->staff_id,
                'first_name'   => $item->first_name,
                'middle_name'  => $item->middle_name,
                'last_name'    => $item->last_name,
                'suffix'       => $item->suffix,
                'username'     => $item->username,
                'position'     => $item->position,
                'status'       => $item->status,
                'activated_at' => $item->activated_at,
                'created_at'   => $item->created_at,
            ];
        });

        return response()->json($staffs);
    }

    public function metrics(): JsonResponse
    {
        return response()->json([
            'total'    => Staff::count(),
            'active'   => Staff::where('status', 'active')->count(),
            'pending'  => Staff::where('status', 'pending')->count(),
        ]);
    }

    public function update(Request $request, Staff $staff): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'staffId'    => 'required|string|max:50|unique:staffs,staff_id,' . $staff->id,
            'firstName'  => 'required|string|max:100',
            'middleName' => 'nullable|string|max:100',
            'lastName'   => 'required|string|max:100',
            'suffix'     => 'nullable|in:' . implode(',', self::SUFFIXES),
            'username'   => 'required|string|max:50|unique:staffs,username,' . $staff->id,
            'position'   => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $staff->update([
            'staff_id'    => $data['staffId'],
            'first_name'  => $data['firstName'],
            'middle_name' => $data['middleName'] ?? null,
            'last_name'   => $data['lastName'],
            'suffix'      => ($data['suffix'] ?? 'none') === 'none' ? null : $data['suffix'],
            'username'    => $data['username'],
            'position'    => $data['position'] ?? null,
        ]);

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: 'staff.updated',
            category: AuditLog::CAT_USER_MGMT,
            description: 'Admin updated staff account for "' . trim($staff->first_name . ' ' . $staff->last_name) . '" (' . $staff->staff_id . ').',
            subject: $staff,
            subjectLabel: $staff->staff_id,
        );

        return response()->json([
            'message' => 'Staff account updated successfully',
            'staff' => [
                'id'         => $staff->id,
                'staff_id'   => $staff->staff_id,
                'first_name' => $staff->first_name,
                'middle_name'=> $staff->middle_name,
                'last_name'  => $staff->last_name,
                'suffix'     => $staff->suffix,
                'username'   => $staff->username,
                'position'   => $staff->position,
                'status'     => $staff->status,
            ],
        ]);
    }

    public function destroy(Request $request, Staff $staff): JsonResponse
    {
        $staffLabel = $staff->staff_id;
        $staffName  = trim($staff->first_name . ' ' . $staff->last_name);

        $staff->delete();

        AuditLogService::log(
            actor: $request->user('admin'),
            role: AuditLog::ROLE_ADMIN,
            action: 'staff.deleted',
            category: AuditLog::CAT_USER_MGMT,
            description: 'Admin deleted staff account for "' . $staffName . '" (' . $staffLabel . ').',
            subjectLabel: $staffLabel,
        );

        return response()->json([
            'message' => 'Staff account deleted successfully',
        ]);
    }
}