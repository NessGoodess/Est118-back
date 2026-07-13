<?php

namespace App\Http\Controllers;

use App\Exports\CredentialPrintingExport;
use App\Http\Requests\UpdateStudentCredentialTrackingRequest;
use App\Models\ClassGroup;
use App\Models\Student;
use App\Models\StudentCredentialTracking;
use App\Enums\EnrollmentStatus;
use App\Services\CredentialPrintingService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentCredentialPrintingController extends Controller
{
    public function __construct(
        protected CredentialPrintingService $credentialPrintingService
    ) {}

    public function classGroupsForGrade(int $grade): JsonResponse
    {
        $data = $this->credentialPrintingService->classGroupsForGrade($grade);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function rows(ClassGroup $classGroup): JsonResponse
    {
        $payload = $this->credentialPrintingService->rowsForClassGroup($classGroup);

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function exportExcel(ClassGroup $classGroup): BinaryFileResponse
    {
        [$headings, $rows] = $this->credentialPrintingService->exportMatrix($classGroup);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', ($classGroup->gradeLevel?->name ?? 'grado').'_grupo_'.$classGroup->name);

        return Excel::download(
            new CredentialPrintingExport($headings, $rows),
            'credenciales_'.$safe.'_'.$classGroup->id.'.xlsx'
        );
    }

    /**
     * ZIP con fotos (compatible con Windows; RAR no está disponible en el servidor).
     */
    public function photosZip(ClassGroup $classGroup): BinaryFileResponse|\Illuminate\Http\Response
    {
        $built = $this->credentialPrintingService->buildPhotosZip($classGroup);
        if (count($built['names']) === 0) {
            if (is_file($built['path'])) {
                unlink($built['path']);
            }

            return response()->json([
                'success' => false,
                'message' => 'No hay fotos originales en disco para este grupo.',
            ], 422);
        }

        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'fotos_'.$classGroup->id);

        return response()->download($built['path'], $safe.'.zip')->deleteFileAfterSend(true);
    }

    public function updateTracking(UpdateStudentCredentialTrackingRequest $request, Student $student): JsonResponse
    {
        $enrollment = $student->enrollments()
            ->where('status', EnrollmentStatus::Active)
            ->first();

        if (! $enrollment || (int) $enrollment->academic_year_id !== (int) $request->validated('academic_year_id')) {
            return response()->json([
                'success' => false,
                'message' => 'El alumno no tiene inscripción activa para ese ciclo escolar.',
            ], 422);
        }

        $data = collect($request->validated())->except('academic_year_id')->all();

        $tracking = StudentCredentialTracking::updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_year_id' => (int) $request->validated('academic_year_id'),
            ],
            $data
        );

        $enrollment->loadMissing('classGroup.gradeLevel', 'classGroup.academicYear');
        $classGroup = $enrollment->classGroup;
        $student->refresh()->load([
            'profile.address',
            'guardians.profile',
            'credentialTrackings' => fn ($q) => $q->where('academic_year_id', $tracking->academic_year_id),
            'workshops' => fn ($q) => $q->wherePivot('academic_year_id', $tracking->academic_year_id),
        ]);

        $row = $classGroup
            ? $this->credentialPrintingService->buildRow($student, $classGroup)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'tracking' => $tracking->only([
                    'credential_printed',
                    'nfc_ready',
                    'ready_to_deliver',
                    'paid',
                    'delivered',
                    'lost',
                    'replacement_count',
                ]),
                'row' => $row,
            ],
        ]);
    }
}
