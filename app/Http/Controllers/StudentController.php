<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentDetailResource;
use App\Http\Resources\StudentListItemResource;
use App\Models\Student;
use App\Services\StudentPhotoPathService;
use App\Services\StudentPhotoService;
use App\Services\StudentsService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;

class StudentController extends Controller
{
    public function __construct(
        protected StudentsService $studentsService,
        protected StudentPhotoService $studentPhotoService,
    ) {}

    /**
     * Display a listing of the students.
     */
    public function index(): JsonResponse
    {
        $students = $this->studentsService->listIndex();

        if ($students->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron estudiantes',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => StudentListItemResource::collection($students)->resolve(),
        ]);
    }

    /**
     * Display the specified student.
     */
    public function show(Student $student): JsonResponse
    {
        $detail = $this->studentsService->findForDetail($student->id);

        return response()->json([
            'success' => true,
            'data' => (new StudentDetailResource($detail))->resolve(),
        ]);
    }

    /**
     * Update student profile and/or address (partial PATCH).
     */
    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        try {
            $updated = $this->studentsService->updateProfileAndAddress($student, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => (new StudentDetailResource($updated))->resolve(),
        ]);
    }

    /**
     * Get all students by grade
     */
    public function getStudentsByGrade(int $grade_id): JsonResponse
    {
        $students = $this->studentsService->listByGrade($grade_id);

        return response()->json([
            'success' => true,
            'data' => StudentListItemResource::collection($students)->resolve(),
        ]);
    }

    /**
     * Photo status for capture/renew flow.
     */
    public function photoStatus(int $studentId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->studentsService->photoStatusPayload($studentId),
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

        $student = Student::with(['profile'])->findOrFail($studentId);

        $result = $this->studentPhotoService->storeStudentPhoto($student, $request->file('photo'));

        $student->refresh()->load([
            'profile',
            'currentEnrollment.classGroup.gradeLevel',
            'currentEnrollment.classGroup',
        ]);
        $photoUrl = app(StudentPhotoPathService::class)->signedUrl($student, 'profile');

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
