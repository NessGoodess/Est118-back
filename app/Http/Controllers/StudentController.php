<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Services\StudentsService;

class StudentController extends Controller
{
    protected $studentsService;

    public function __construct(StudentsService $studentsService)
    {
        $this->studentsService = $studentsService;
    }

    /**
     * Display a listing of the students.
     */
    public function index(): JsonResponse
    {
        $students = Student::with([
            'profile:id,first_name,last_name',
            'enrollments' => fn($q) => $q->where('status', 'active')->with([
                'classGroup:id,name,grade_level_id',
                'classGroup.gradeLevel:id,name'
            ]),
        ])
            ->get()
            ->map(fn($student) => [
                'id' => $student->id,
                'credential_id' => $student->credential_id,
                'name' => $student->profile->first_name . ' ' . $student->profile->last_name,
                'current_grade' => optional($student->enrollments->first()?->classGroup?->gradeLevel)?->name ?? 'N/A',
                'current_group' => optional($student->enrollments->first()?->classGroup)?->name ?? 'N/A',
                'photo_url' => $student->profile->profile_picture_url,
            ]);
        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    /**
     * Display the specified student.
     */
    public function show($id): JsonResponse
    {
        $student = Student::with([
            'profile',
            'enrollments.classGroup.gradeLevel',
            'enrollments.classGroup.academicYear',
            'enrollments.classGroup.schoolClasses.subject',
            'guardians.profile'
        ])
            ->findOrFail($id);

        $currentEnrollment = $student->enrollments->where('status', 'active')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'student_info' => [
                    'id' => $student->id,
                    'name' => $student->profile->first_name . ' ' . $student->profile->last_name,
                    'birth_date' => $student->profile->birth_date,
                    'gender' => $student->profile->gender,
                    'phone' => $student->profile->phone_number,
                    'address' => $student->profile->address
                ],
                'current_enrollment' => $currentEnrollment ? [
                    'grade_level' => $currentEnrollment->classGroup->gradeLevel->name,
                    'class_group' => $currentEnrollment->classGroup->name,
                    'academic_year' => $currentEnrollment->classGroup->academicYear->description,
                    'enrollment_date' => $currentEnrollment->enrollment_date
                ] : null,
                'subjects' => $currentEnrollment ?
                    $currentEnrollment->classGroup->schoolClasses->map(function ($class) {
                        return $class->subject->name;
                    })->unique()->values() : [],
                'guardians' => $student->guardians->map(function ($guardian) {
                    return [
                        'name' => $guardian->profile->first_name . ' ' . $guardian->profile->last_name,
                        'relationship' => $guardian->pivot->relationship,
                        'phone' => $guardian->profile->phone_number
                    ];
                })
            ]
        ]);
    }


    /**
     * Get all students by grade
     */
    public function getStudentsByGrade($grade_id): JsonResponse
    {
        $students = Student::with([
            'profile',
            'enrollments.classGroup.gradeLevel',
            'enrollments.classGroup.academicYear',
            'enrollments.classGroup.schoolClasses.subject',
        ])
            ->whereHas('enrollments', function ($q) use ($grade_id) {
                $q->where('status', 'active')
                    ->whereHas('classGroup', function ($q) use ($grade_id) {
                        $q->where('grade_level_id', $grade_id);
                    });
            })
            ->get()
            ->map(function ($student) {
                $currentEnrollment = $student->enrollments->where('status', 'active')->first();
                return [
                    'id' => $student->id,
                    'name' => $student->profile->first_name . ' ' . $student->profile->last_name,
                    'birth_date' => $student->profile->birth_date,
                    'gender' => $student->profile->gender,
                    'phone' => $student->profile->phone_number,
                    'grade_level' => $currentEnrollment->classGroup->gradeLevel->name,
                    'class_group' => $currentEnrollment->classGroup->name,
                    'photo_url' => $this->studentsService->generatePrivateImageUrl(
                        $currentEnrollment->classGroup->gradeLevel->name,
                        $currentEnrollment->classGroup->name,
                        $student->profile->profile_picture
                    ),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    public function getStudent($id){
        $student = Student::with([
            'profile',
            'enrollments.classGroup.gradeLevel',
            'enrollments.classGroup.academicYear',
            'enrollments.classGroup.schoolClasses.subject',
            'guardians.profile'
        ])
            ->whereHas('enrollments', function ($q) use ($id) {
                $q->where('status', 'active')
                    ->whereHas('classGroup', function ($q) use ($id) {
                        $q->where('grade_level_id', $id);
                    });
            })
            ->get()
            ->map(function ($student) {
                $currentEnrollment = $student->enrollments->where('status', 'active')->first();
                return [
                    'id' => $student->id,
                    'name' => $student->profile->first_name . ' ' . $student->profile->last_name,
                    'birth_date' => $student->profile->birth_date,
                    'gender' => $student->profile->gender,
                    'phone' => $student->profile->phone_number,
                    'address' => $student->profile->address,
                    'current_enrollment' => $currentEnrollment ? [
                        'grade_level' => $currentEnrollment->classGroup->gradeLevel->name,
                        'class_group' => $currentEnrollment->classGroup->name,
                        'academic_year' => $currentEnrollment->classGroup->academicYear->description,
                        'enrollment_date' => $currentEnrollment->enrollment_date
                    ] : null,
                    'subjects' => $currentEnrollment ?
                        $currentEnrollment->classGroup->schoolClasses->map(function ($class) {
                            return $class->subject->name;
                        })->unique()->values() : [],
                    'guardians' => $student->guardians->map(function ($guardian) {
                        return [
                            'name' => $guardian->profile->first_name . ' ' . $guardian->profile->last_name,
                            'relationship' => $guardian->pivot->relationship,
                            'phone' => $guardian->profile->phone_number
                        ];
                    })
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $student
        ]);
    
    }
}


