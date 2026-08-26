<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Address;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentCredentialTracking;
use Illuminate\Support\Facades\Storage;

class CredentialPrintingService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function classGroupsForGrade(int $gradeId): array
    {
        return ClassGroup::query()
            ->where('grade_level_id', $gradeId)
            ->with(['gradeLevel:id,name', 'academicYear:id,description'])
            ->withCount([
                'enrollments as active_students_count' => function ($q) {
                    $q->where('status', EnrollmentStatus::Active);
                },
            ])
            ->orderByDesc('academic_year_id')
            ->orderBy('name')
            ->get()
            ->map(function (ClassGroup $cg) {
                $grade = $cg->gradeLevel?->name ?? '';
                $year = $cg->academicYear?->description ?? '';

                return [
                    'id' => $cg->id,
                    'name' => $cg->name,
                    'grade_level_id' => $cg->grade_level_id,
                    'grade_name' => $grade,
                    'academic_year_id' => $cg->academic_year_id,
                    'academic_year' => $year,
                    'label' => trim($grade.' — Grupo '.$cg->name.($year ? ' ('.$year.')' : '')),
                    'active_students_count' => (int) $cg->active_students_count,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Filas para UI / JSON y exportación.
     *
     * @return array{meta: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    public function rowsForClassGroup(ClassGroup $classGroup): array
    {
        $classGroup->loadMissing(['gradeLevel', 'academicYear']);

        $enrollments = Enrollment::query()
            ->where('class_group_id', $classGroup->id)
            ->where('status', EnrollmentStatus::Active)
            ->with([
                'student.profile.address',
                'student.guardians.profile',
                'student.credentialTrackings' => function ($q) use ($classGroup) {
                    $q->where('academic_year_id', $classGroup->academic_year_id);
                },
                'student.workshops' => function ($q) use ($classGroup) {
                    $q->wherePivot('academic_year_id', $classGroup->academic_year_id);
                },
            ])
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;
            if (! $student || ! $student->profile) {
                continue;
            }
            $rows[] = $this->buildRow($student, $classGroup);
        }

        return [
            'meta' => [
                'class_group_id' => $classGroup->id,
                'grade_name' => $classGroup->gradeLevel?->name,
                'group_name' => $classGroup->name,
                'academic_year_id' => $classGroup->academic_year_id,
                'academic_year' => $classGroup->academicYear?->description,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRow(Student $student, ClassGroup $classGroup): array
    {
        $profile = $student->profile;
        $grade = $classGroup->gradeLevel?->name ?? '';
        $group = $classGroup->name;
        $fullName = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));

        $workshops = $student->relationLoaded('workshops')
            ? $student->workshops
            : $student->workshops()->wherePivot('academic_year_id', $classGroup->academic_year_id)->get();
        $workshopNames = $workshops->pluck('name')->filter()->unique()->implode(' | ');

        $addressStr = $this->formatAddress($profile->address);
        $guardian = $student->guardians->first();
        $gProfile = $guardian?->profile;
        $tutorName = $gProfile
            ? trim(($gProfile->first_name ?? '').' '.($gProfile->last_name ?? ''))
            : '';
        $tutorPhone = $gProfile?->phone_number;
        $phone = $tutorPhone ?: ($profile->phone_number ?? '');

        $photoFilename = $profile->profile_picture;
        $hasPhoto = (bool) $photoFilename;

        $curp = $profile->national_id ?? '';
        $hasCurp = $curp !== '';
        $hasAddress = $addressStr !== '';
        $hasTutor = $tutorName !== '';
        $hasPhone = ($phone ?? '') !== '';
        $missing = [];
        if (! $hasPhoto) {
            $missing[] = 'foto';
        }
        if (! $hasCurp) {
            $missing[] = 'CURP';
        }
        if (! $hasAddress) {
            $missing[] = 'dirección';
        }
        if (! $hasTutor) {
            $missing[] = 'tutor';
        }
        if (! $hasPhone) {
            $missing[] = 'teléfono';
        }
        $dataComplete = count($missing) === 0;

        $tracking = $student->credentialTrackings
            ->firstWhere('academic_year_id', $classGroup->academic_year_id);

        $trackingPayload = $tracking ? [
            'credential_printed' => $tracking->credential_printed,
            'nfc_ready' => $tracking->nfc_ready,
            'ready_to_deliver' => $tracking->ready_to_deliver,
            'paid' => $tracking->paid,
            'delivered' => $tracking->delivered,
            'lost' => $tracking->lost,
            'replacement_count' => (int) $tracking->replacement_count,
        ] : [
            'credential_printed' => false,
            'nfc_ready' => false,
            'ready_to_deliver' => false,
            'paid' => false,
            'delivered' => false,
            'lost' => false,
            'replacement_count' => 0,
        ];

        $lineaImpresion = implode(' | ', array_filter([
            $fullName,
            trim($grade.' '.$group),
            $workshopNames ?: null,
            $addressStr ?: null,
            $photoFilename ?: null,
        ], fn ($v) => $v !== null && $v !== ''));

        return [
            'student_id' => $student->id,
            'credential_id' => $student->credential_id,
            'full_name' => $fullName,
            'grade' => $grade,
            'group' => $group,
            'workshop_names' => $workshopNames,
            'curp' => $curp,
            'address' => $addressStr,
            'tutor_name' => $tutorName,
            'tutor_relationship' => $guardian?->pivot?->relationship,
            'phone' => $phone ?? '',
            'photo_filename' => $photoFilename,
            'has_photo' => $hasPhoto,
            'data_complete' => $dataComplete,
            'data_missing' => $missing,
            'linea_impresion' => $lineaImpresion,
            'tracking' => $trackingPayload,
            'replacement_label' => $this->replacementLabel((int) $trackingPayload['replacement_count']),
        ];
    }

    public function replacementLabel(int $count): string
    {
        return match ($count) {
            0 => 'Sin repuesto',
            1 => '1er repuesto',
            2 => '2do repuesto',
            3 => '3er repuesto',
            default => $count.'° repuesto',
        };
    }

    public function formatAddress(?Address $address): string
    {
        if (! $address) {
            return '';
        }
        $street = trim(($address->street_type ?? '').' '.($address->street_name ?? ''));
        $parts = array_filter([
            $street !== '' ? $street : null,
            $address->house_number ? 'No. '.$address->house_number : null,
            $address->apartament_number ? 'Int. '.$address->apartament_number : null,
            $address->neighborhood_name
                ? trim(($address->neighborhood_type ?? '').' '.$address->neighborhood_name)
                : null,
            $address->postal_code ? 'C.P. '.$address->postal_code : null,
            $address->city,
            $address->state,
        ]);

        return implode(', ', $parts);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    public function exportMatrix(ClassGroup $classGroup): array
    {
        $bundle = $this->rowsForClassGroup($classGroup);
        $headings = [
            'ID alumno',
            'Folio credencial',
            'Nombre completo',
            'Grado',
            'Grupo',
            'Taller (nombre completo)',
            'CURP',
            'Dirección',
            'Tutor',
            'Teléfono',
            'Nombre archivo foto',
            'Tiene foto',
            'Datos completos',
            'Faltantes',
            'Línea impresión (nombre | grado grupo | taller | dirección | foto)',
            'Credencial impresa',
            'NFC listo',
            'Listo para entregar',
            'Pagado',
            'Entregado',
            'Perdido',
            'Repuesto',
        ];

        $rows = [];
        foreach ($bundle['rows'] as $r) {
            $t = $r['tracking'];
            $rows[] = [
                $r['student_id'],
                $r['credential_id'] ?? '',
                $r['full_name'],
                $r['grade'],
                $r['group'],
                $r['workshop_names'],
                $r['curp'],
                $r['address'],
                $r['tutor_name'],
                $r['phone'],
                $r['photo_filename'] ?? '',
                $r['has_photo'] ? 'Sí' : 'No',
                $r['data_complete'] ? 'Sí' : 'No',
                implode(', ', $r['data_missing'] ?? []),
                $r['linea_impresion'],
                $t['credential_printed'] ? 'Sí' : 'No',
                $t['nfc_ready'] ? 'Sí' : 'No',
                $t['ready_to_deliver'] ? 'Sí' : 'No',
                $t['paid'] ? 'Sí' : 'No',
                $t['delivered'] ? 'Sí' : 'No',
                $t['lost'] ? 'Sí' : 'No',
                $r['replacement_label'],
            ];
        }

        return [$headings, $rows];
    }

    /**
     * Ruta del archivo original en disco privado (misma convención que PrivateImageController).
     */
    public function originalPhotoRelativePath(Student $student, ClassGroup $classGroup): ?string
    {
        $student->loadMissing([
            'profile',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ]);

        $pathService = app(StudentPhotoPathService::class);
        $path = $pathService->resolveRelativePath($student, 'original');

        if ($path && Storage::disk('private')->exists($path)) {
            return $path;
        }

        $profile = $pathService->resolveRelativePath($student, 'profile');

        return ($profile && Storage::disk('private')->exists($profile)) ? $profile : null;
    }

    /**
     * @return array{path: string, names: array<int, string>}
     */
    public function buildPhotosZip(ClassGroup $classGroup): array
    {
        $disk = Storage::disk('private');
        $zipPath = storage_path('app/temp/credencial_fotos_'.$classGroup->id.'_'.uniqid('', true).'.zip');
        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo ZIP.');
        }

        $bundle = $this->rowsForClassGroup($classGroup);
        $added = [];
        foreach ($bundle['rows'] as $row) {
            $student = Student::with('profile')->find($row['student_id']);
            if (! $student) {
                continue;
            }
            $rel = $this->originalPhotoRelativePath($student, $classGroup);
            if (! $rel || ! $disk->exists($rel)) {
                continue;
            }
            $fn = $row['photo_filename'] ?: basename($rel);
            $entry = 'fotos/'.$student->id.'_'.$fn;
            $zip->addFile($disk->path($rel), $entry);
            $added[] = $entry;
        }
        $zip->close();

        return ['path' => $zipPath, 'names' => $added];
    }
}
