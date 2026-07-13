<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Services\StudentsService;
use Illuminate\Support\Facades\URL;
use App\Services\StudentPhotoService;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    protected $studentsService;
    protected StudentPhotoService $studentPhotoService;

    public function __construct(StudentsService $studentsService, StudentPhotoService $studentPhotoService)
    {
        $this->studentsService = $studentsService;
        $this->studentPhotoService = $studentPhotoService;
    }

    /**
     * Display a listing of the students.
     */
    public function index(): JsonResponse
    {
        $students = Student::with([
            'profile:id,first_name,last_name,profile_picture,updated_at',
            'enrollments' => fn($q) => $q->where('status', 'active')->with([
                'classGroup:id,name,grade_level_id',
                'classGroup.gradeLevel:id,name'
            ]),
        ])
            ->get()
            ->map(function ($student) {
                $enrollment = $student->enrollments->first();
                $grade = optional($enrollment?->classGroup?->gradeLevel)?->name ?? 'N/A';
                $group = optional($enrollment?->classGroup)?->name ?? 'N/A';
                return [
                    'id' => $student->id,
                    'credential_id' => $student->credential_id,
                    'name' => $student->profile->first_name . ' ' . $student->profile->last_name,
                    'current_grade' => $grade,
                    'current_group' => $group,
                    'photo_url' => $this->getUrlPhotoThumb(
                        $student->id,
                        $student->profile->profile_picture,
                        $student->profile->updated_at
                    )
                ];
            });

        if ($students->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron estudiantes',
            ], 404);
        }
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
            'profile.address',
            'enrollments.classGroup.gradeLevel',
            'enrollments.classGroup.academicYear',
            'enrollments.classGroup.schoolClasses.subject',
            'guardians.profile',
        ])
            ->findOrFail($id);

        $profile = $student->profile;
        $currentEnrollment = $student->enrollments->where('status', 'active')->first();

        $address = $profile?->relationLoaded('address') ? $profile->address : null;

        return response()->json([
            'success' => true,
            'data' => [
                'student_info' => [
                    'id' => $student->id,
                    'credential_id' => $student->credential_id,
                    'full_name' => trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')),
                    'first_name' => $profile?->first_name,
                    'last_name' => $profile?->last_name,
                    /** @compat legacy campo name */
                    'name' => trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')),
                    'national_id' => $profile?->national_id,
                    'birth_date' => $profile?->birth_date,
                    'gender' => $profile?->gender,
                    'phone' => $profile?->phone_number,
                    'phone_secondary' => $profile?->phone_second_number,
                    'email' => $profile?->email,
                    'profile_picture_filename' => $profile?->profile_picture,
                    'profile_updated_at' => $profile?->updated_at,
                ],
                'photos' => [
                    'thumbnail_url' => $this->getUrlPhotoSized($student->id, $profile?->profile_picture, $profile?->updated_at, 'thumb'),
                    'profile_url' => $this->getUrlPhotoSized($student->id, $profile?->profile_picture, $profile?->updated_at, 'profile'),
                    /** Resolución completa (archivo subido) para reconocimiento facial */
                    'original_url' => $this->getUrlPhotoSized($student->id, $profile?->profile_picture, $profile?->updated_at, 'original'),
                ],
                'address_detail' => $address ? $address->only([
                    'street_type',
                    'street_name',
                    'house_number',
                    'apartament_number',
                    'neighborhood_type',
                    'neighborhood_name',
                    'postal_code',
                    'city',
                    'state',
                ]) : null,
                'current_enrollment' => $currentEnrollment ? [
                    'enrollment_id' => $currentEnrollment->id,
                    'grade_level' => optional($currentEnrollment->classGroup?->gradeLevel)->name,
                    'class_group' => optional($currentEnrollment->classGroup)->name,
                    'academic_year' => optional($currentEnrollment->classGroup?->academicYear)->description,
                    /** Columnas reales: enrollments.created_at / updated_at (no enrollment_date) */
                    'recorded_at' => $currentEnrollment->created_at,
                    'updated_at' => $currentEnrollment->updated_at,
                    'is_new_admission' => $currentEnrollment->is_new_admission,
                    'is_approved' => $currentEnrollment->is_approved,
                    'promotion_result' => $currentEnrollment->promotion_result instanceof \BackedEnum ? $currentEnrollment->promotion_result->value : $currentEnrollment->promotion_result,
                ] : null,
                'subjects' => $currentEnrollment ?
                    $currentEnrollment->classGroup->schoolClasses->map(fn ($class) => $class->subject?->name)->filter()->unique()->values() : [],
                'all_enrollments' => $student->enrollments->sortByDesc('id')->values()->map(function ($enrollment) {
                    return [
                        'id' => $enrollment->id,
                        'status' => $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : (string) $enrollment->status,
                        'grade_level' => optional(optional($enrollment->classGroup)->gradeLevel)->name,
                        'class_group' => optional($enrollment->classGroup)->name,
                        'academic_year' => optional(optional($enrollment->classGroup)->academicYear)->description,
                        'is_new_admission' => $enrollment->is_new_admission,
                        'is_approved' => $enrollment->is_approved,
                        'promotion_result' => $enrollment->promotion_result instanceof \BackedEnum ? $enrollment->promotion_result->value : $enrollment->promotion_result,
                        'created_at' => $enrollment->created_at,
                        'updated_at' => $enrollment->updated_at,
                    ];
                }),
                'guardians' => $student->guardians->map(function ($guardian) {
                    $p = $guardian->profile;

                    return [
                        'name' => trim(($p?->first_name ?? '') . ' ' . ($p?->last_name ?? '')),
                        'relationship' => $guardian->pivot->relationship,
                        'phone' => $p?->phone_number,
                    ];
                }),
            ],
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
                    'photo_url' => $this->getUrlPhotoThumb(
                        $student->id,
                        $student->profile->profile_picture,
                        $student->profile->updated_at
                    )
                ];
            });


        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    public function getStudent($id)
    {
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
                        'recorded_at' => $currentEnrollment->created_at,
                    ] : null,
                    'subjects' => $currentEnrollment ?
                        $currentEnrollment->classGroup->schoolClasses->map(function ($class) {
                            return $class->subject?->name;
                        })->filter()->unique()->values() : [],
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


    /**
     * @param mixed $updated_at Valor típ Carbon o null desde Eloquent timestamps
     */
    private function getUrlPhotoSized(int $studentId, ?string $photo, $updated_at, string $size = 'thumb'): ?string
    {
        if (!$photo) {
            return null;
        }

        $version = null;
        if ($updated_at && method_exists($updated_at, 'timestamp')) {
            $version = $updated_at->timestamp;
        }

        return URL::temporarySignedRoute(
            'private.image',
            now()->addMinutes(60),
            [
                'id' => $studentId,
                'size' => $size,
                'v' => $version ?? time(),
            ]
        );
    }

    private function getUrlPhotoThumb(int $studentId, ?string $photo, $updated_at): ?string
    {
        return $this->getUrlPhotoSized($studentId, $photo, $updated_at, 'thumb');
    }

    /**
     * Photo status for capture/renew flow.
     */
    public function photoStatus(int $studentId): JsonResponse
    {
        $student = Student::with([
            'profile:id,first_name,last_name,profile_picture,updated_at',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ])->findOrFail($studentId);

        $filename = $student->profile?->profile_picture;
        $hasPhoto = ! empty($filename);
        $photoUrl = $hasPhoto
            ? $this->getUrlPhotoThumb($student->id, $filename, $student->profile?->updated_at)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'student_id' => $student->id,
                'student_name' => trim(($student->profile?->first_name ?? '') . ' ' . ($student->profile?->last_name ?? '')),
                'has_photo' => $hasPhoto,
                'action_label' => $hasPhoto ? 'Renovar' : 'Capturar',
                'photo_url' => $photoUrl,
                'grade' => $student->currentEnrollment?->classGroup?->gradeLevel?->name,
                'group' => $student->currentEnrollment?->classGroup?->name,
            ],
        ]);
    }

    /**
     * Upload and optimize student photo.
     */
    public function uploadPhoto(Request $request, int $studentId): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:8192'],
        ]);

        $student = Student::with(['profile', 'currentEnrollment.classGroup.gradeLevel', 'currentEnrollment.classGroup'])
            ->findOrFail($studentId);

        $result = $this->studentPhotoService->storeStudentPhoto($student, $request->file('photo'));

        $student->refresh()->load('profile');
        $photoUrl = $this->getUrlPhotoThumb($student->id, $student->profile?->profile_picture, $student->profile?->updated_at);

        return response()->json([
            'success' => true,
            'message' => 'Foto guardada y optimizada correctamente.',
            'data' => [
                ...$result,
                'photo_url' => $photoUrl,
            ],
        ]);
    }
}
