<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Teacher;
use App\Services\ActivationCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TeacherController extends Controller
{
    private const POSITIONS = [
        'Teacher I',
        'Teacher II',
        'Teacher III',
        'Teacher IV',
        'Teacher V',
        'Teacher VI',
        'Teacher VII',
        'Master Teacher I',
        'Master Teacher II',
        'Master Teacher III',
        'Master Teacher IV',
        'Master Teacher V',
    ];

    private const CLASS_ADVISORIES = ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];

    private const SUFFIXES = ['none', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

    public function store(Request $request, ActivationCodeService $activationCodes): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teacherId'     => 'required|string|max:50|unique:teachers,teacher_id',
            'firstName'     => 'required|string|max:100',
            'middleName'    => 'nullable|string|max:100',
            'lastName'      => 'required|string|max:100',
            'suffix'        => 'nullable|in:' . implode(',', self::SUFFIXES),
            'username'      => 'required|string|max:50|unique:teachers,username',
            'position'      => 'required|in:' . implode(',', self::POSITIONS),
            'classAdvisory' => 'required|in:' . implode(',', self::CLASS_ADVISORIES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $teacher = Teacher::create([
            'teacher_id'     => $data['teacherId'],
            'first_name'     => $data['firstName'],
            'middle_name'    => $data['middleName'] ?? null,
            'last_name'      => $data['lastName'],
            'suffix'         => ($data['suffix'] ?? 'none') === 'none' ? null : $data['suffix'],
            'username'       => $data['username'],
            'position'       => $data['position'],
            'class_advisory' => $data['classAdvisory'],
            'status'         => 'pending',
        ]);

        $activationCode = $activationCodes->generateFor($teacher->id);

        return response()->json([
            'message' => 'Teacher account created successfully',
            'teacher' => [
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
            ],
            'activation_code' => $activationCode,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $teachers = Teacher::query()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('teacher_id', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($teachers);
    }

    public function metrics(): JsonResponse
    {
        $total    = Teacher::count();
        $active   = Teacher::where('status', 'active')->count();
        $inactive = Teacher::where('status', 'inactive')->count();
        $pending  = Teacher::where('status', 'pending')->count();

        return response()->json([
            'total'    => $total,
            'active'   => $active,
            'inactive' => $inactive,
            'pending'  => $pending,
        ]);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teacherId'     => 'required|string|max:50|unique:teachers,teacher_id,' . $teacher->id,
            'firstName'     => 'required|string|max:100',
            'middleName'    => 'nullable|string|max:100',
            'lastName'      => 'required|string|max:100',
            'suffix'        => 'nullable|in:' . implode(',', self::SUFFIXES),
            'username'      => 'required|string|max:50|unique:teachers,username,' . $teacher->id,
            'position'      => 'required|in:' . implode(',', self::POSITIONS),
            'classAdvisory' => 'required|in:' . implode(',', self::CLASS_ADVISORIES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $teacher->update([
            'teacher_id'     => $data['teacherId'],
            'first_name'     => $data['firstName'],
            'middle_name'    => $data['middleName'] ?? null,
            'last_name'      => $data['lastName'],
            'suffix'         => ($data['suffix'] ?? 'none') === 'none' ? null : $data['suffix'],
            'username'       => $data['username'],
            'position'       => $data['position'],
            'class_advisory' => $data['classAdvisory'],
        ]);

        return response()->json([
            'message' => 'Teacher account updated successfully',
            'teacher' => [
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
            ],
        ]);
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $teacher->delete();

        return response()->json([
            'message' => 'Teacher account deleted successfully',
        ]);
    }
}
