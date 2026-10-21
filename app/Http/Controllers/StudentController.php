<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Exceptions\GuardianCurpConflictException;
use App\Http\Requests\AttachStudentGuardianRequest;
use App\Http\Requests\MergeStudentGuardianRequest;
use App\Http\Requests\StoreStudentGuardianRequest;
use App\Http\Requests\StoreStudentSiblingRequest;
use App\Http\Requests\UpdateStudentGuardianRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentDetailResource;
use App\Http\Resources\StudentListItemResource;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\CredentialPrintingService;
use App\Services\StudentFamilyService;
use App\Services\StudentPhotoPathService;
use App\Services\StudentPhotoService;
use App\Services\StudentsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;

class StudentController extends Controller
{
    public function __construct(
        protected StudentsService $studentsService,
        protected StudentFamilyService $studentFamilyService,
        protected StudentPhotoService $studentPhotoService,
        protected CredentialPrintingService $credentialPrintingService,
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
     * Create or link a guardian to this student (personal data + address).
     */
    public function storeGuardian(StoreStudentGuardianRequest $request, Student $student): JsonResponse
    {
        try {
            $updated = $this->studentsService->storeGuardian($student, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => (new StudentDetailResource($updated))->resolve(),
        ], 201);
    }

    /**
     * Update the guardian profile linked to this student and this student's relationship.
     */
    public function updateGuardian(UpdateStudentGuardianRequest $request, Student $student, Guardian $guardian): JsonResponse
    {
        try {
            $updated = $this->studentsService->updateGuardian($student, $guardian, $request->validated());
        } catch (GuardianCurpConflictException $e) {
            return response()->json([
                'success' => false,
                'code' => 'guardian_curp_exists',
                'message' => $e->getMessage(),
                'canonical' => $e->canonical,
            ], 409);
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
     * Search enrolled students by name/CURP (and optional grade/group filters).
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'grade' => ['nullable', 'string', 'max:20'],
            'group' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->studentFamilyService->searchStudents(
                (string) ($validated['q'] ?? ''),
                $validated['grade'] ?? null,
                $validated['group'] ?? null,
            ),
        ]);
    }

    public function siblings(Student $student): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->studentFamilyService->listSiblings($student),
        ]);
    }

    public function storeSibling(StoreStudentSiblingRequest $request, Student $student): JsonResponse
    {
        try {
            $updated = $this->studentFamilyService->linkSibling(
                $student,
                (int) $request->validated('sibling_student_id')
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => (new StudentDetailResource($updated))->resolve(),
        ], 201);
    }

    public function destroySibling(Student $student, Student $sibling): JsonResponse
    {
        $updated = $this->studentFamilyService->unlinkSibling($student, $sibling->id);

        return response()->json([
            'success' => true,
            'data' => (new StudentDetailResource($updated))->resolve(),
        ]);
    }

    public function siblingGuardians(Student $student): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->studentFamilyService->siblingGuardians($student),
        ]);
    }

    public function attachGuardian(AttachStudentGuardianRequest $request, Student $student): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->studentFamilyService->attachGuardian(
                $student,
                (int) $data['guardian_id'],
                $data['relationship'] ?? null,
            );
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

    public function mergeGuardian(MergeStudentGuardianRequest $request, Student $student): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->studentFamilyService->mergeGuardianByCurp(
                $student,
                (int) $data['draft_guardian_id'],
                (int) $data['canonical_guardian_id'],
                $data['relationship'] ?? null,
            );
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
     * Cycles available to the directory. Active cycle first, then newest.
     */
    public function academicYears(): JsonResponse
    {
        $years = AcademicYear::query()
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->orderByDesc('year_start')
            ->get(['id', 'year_start', 'year_end', 'description', 'is_active']);

        return response()->json([
            'success' => true,
            'data' => $years,
        ]);
    }

    /**
     * Unified student list by context (directory | credentials).
     * Feature projections on the frontend pass context; this does not grow with UI cases beyond the whitelist.
     * Spanish: Lista de estudiantes unificada por contexto (directorio | credenciales).
     */
    public function listByContext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context' => ['required', 'string', Rule::in(['directory', 'credentials'])],
            'grade_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'class_group_id' => ['nullable', 'integer', 'exists:class_groups,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::enum(EnrollmentStatus::class)],
        ]);

        $context = $validated['context'];

        if ($context === 'credentials') {
            $classGroupId = isset($validated['class_group_id']) ? (int) $validated['class_group_id'] : null;
            if ($classGroupId === null) {
                return response()->json([
                    'success' => true,
                    'data' => ['rows' => [], 'meta' => null],
                ]);
            }

            $classGroup = ClassGroup::query()->findOrFail($classGroupId);
            $payload = $this->credentialPrintingService->rowsForClassGroup($classGroup);

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        }

        // directory
        $gradeId = isset($validated['grade_id']) ? (int) $validated['grade_id'] : null;
        if ($gradeId === null) {
            return response()->json([
                'success' => true,
                'data' => ['rows' => [], 'meta' => null],
            ]);
        }

        $yearId = $this->studentsService->resolveYearId(
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null
        );

        if ($yearId === null) {
            return response()->json([
                'success' => true,
                'data' => ['rows' => [], 'meta' => ['academic_year_id' => null]],
            ]);
        }

        $statuses = $validated['status'] ?? null;
        if ($statuses === []) {
            $statuses = null;
        }

        $students = $this->studentsService->listByGrade($gradeId, $yearId, $statuses);

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => StudentListItemResource::collection($students)->resolve(),
                'meta' => [
                    'grade_id' => $gradeId,
                    'academic_year_id' => $yearId,
                ],
            ],
        ]);
    }

    /**
     * Students of one grade in one academic year (defaults to the active cycle).
     */
    public function getStudentsByGrade(Request $request, int $grade_id): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::enum(EnrollmentStatus::class)],
        ]);

        $yearId = $this->studentsService->resolveYearId(
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null
        );

        if ($yearId === null) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $statuses = $validated['status'] ?? null;
        if ($statuses === []) {
            $statuses = null;
        }

        $students = $this->studentsService->listByGrade($grade_id, $yearId, $statuses);

        return response()->json([
            'success' => true,
            'data' => StudentListItemResource::collection($students)->resolve(),
        ]);
    }

    /**
     * Current photo and archived replacements, grouped later in the UI by cycle.
     */
    public function photoHistory(int $studentId, StudentPhotoPathService $photos): JsonResponse
    {
        $student = Student::query()->findOrFail($studentId);

        return response()->json([
            'success' => true,
            'data' => $photos->historyFor($student),
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
     *
     * Optional upload_id replays the same selection for 30 minutes so a double
     * click or a retry after a dropped response does not archive current/ again.
     */
    public function uploadPhoto(Request $request, int $studentId): JsonResponse
    {
        $validated = $request->validate([
            'photo' => ['required', 'file', 'image', 'max:8192'],
            'upload_id' => ['nullable', 'uuid'],
        ]);

        $uploadId = $validated['upload_id'] ?? null;
        if (! is_string($uploadId)) {
            return response()->json($this->storeUploadedPhoto($request, $studentId));
        }

        $cacheKey = "student-photo-upload:{$studentId}:{$uploadId}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached);
        }

        try {
            $body = Cache::lock("student-photo:{$studentId}", 60)->block(15, function () use ($request, $studentId, $cacheKey): array {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }

                $body = $this->storeUploadedPhoto($request, $studentId);
                Cache::put($cacheKey, $body, now()->addMinutes(30));

                return $body;
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'La foto se está guardando. Espera un momento e inténtalo de nuevo.',
            ], 409);
        }

        return response()->json($body);
    }

    /**
     * @return array{success:bool, message:string, data:array<string, mixed>}
     */
    private function storeUploadedPhoto(Request $request, int $studentId): array
    {
        $student = Student::with(['profile'])->findOrFail($studentId);

        $result = $this->studentPhotoService->storeStudentPhoto($student, $request->file('photo'));

        $student->refresh()->load([
            'profile',
            'currentEnrollment.classGroup.gradeLevel',
            'currentEnrollment.classGroup',
        ]);
        $photoUrl = app(StudentPhotoPathService::class)->signedUrl($student, 'profile');

        return [
            'success' => true,
            'message' => 'Foto guardada y optimizada correctamente.',
            'data' => [
                ...$result,
                'photo_url' => $photoUrl,
            ],
        ];
    }
}
