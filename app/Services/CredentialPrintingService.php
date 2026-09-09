<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CredentialPrintingService
{
    private const EMPTY_TRACKING = [
        'credential_printed' => false,
        'nfc_ready' => false,
        'ready_to_deliver' => false,
        'paid' => false,
        'delivered' => false,
        'lost' => false,
        'replacement_count' => 0,
    ];

    public function __construct(
        private readonly StudentPhotoPathService $photoPaths
    ) {}

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
     * @return array{meta: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    public function rowsForClassGroup(ClassGroup $classGroup): array
    {
        $classGroup->loadMissing(['gradeLevel', 'academicYear']);
        $canSeePhotos = $this->viewerCanSeePhotos();

        $rows = [];
        foreach ($this->activeEnrollments($classGroup) as $enrollment) {
            $student = $enrollment->student;
            if (! $student || ! $student->profile) {
                continue;
            }
            $rows[] = $this->buildRow($student, $classGroup, $canSeePhotos);
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
    public function buildRow(Student $student, ClassGroup $classGroup, ?bool $canSeePhotos = null): array
    {
        $canSeePhotos ??= $this->viewerCanSeePhotos();
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
        $phone = $gProfile?->phone_number ?: ($profile->phone_number ?? '');

        $photo = $this->photoMeta($student, $classGroup);
        $curp = $profile->national_id ?? '';

        $missing = [];
        if (! $photo['has_photo']) {
            $missing[] = 'foto';
        }
        if ($curp === '') {
            $missing[] = 'CURP';
        }
        if ($addressStr === '') {
            $missing[] = 'dirección';
        }
        if ($tutorName === '') {
            $missing[] = 'tutor';
        }
        if (($phone ?? '') === '') {
            $missing[] = 'teléfono';
        }

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
        ] : self::EMPTY_TRACKING;

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
            'photo_filename' => $photo['photo_filename'],
            'photo_url' => $canSeePhotos ? $photo['photo_url'] : null,
            'photo_updated_at' => $photo['photo_updated_at'],
            'photo_freshness' => $photo['photo_freshness'],
            'has_photo' => $photo['has_photo'],
            'data_complete' => count($missing) === 0,
            'data_missing' => $missing,
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
    public function exportMatrix(ClassGroup $classGroup, string $variant = 'credentials'): array
    {
        $bundle = $this->rowsForClassGroup($classGroup);

        return $variant === 'report'
            ? $this->reportMatrix($bundle)
            : $this->credentialsMatrix($bundle);
    }

    public function originalPhotoRelativePath(Student $student): ?string
    {
        $student->loadMissing([
            'profile',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ]);

        $path = $this->photoPaths->resolveRelativePath($student, 'original');
        if ($path && Storage::disk('private')->exists($path)) {
            return $path;
        }

        $profile = $this->photoPaths->resolveRelativePath($student, 'profile');

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

        $classGroup->loadMissing(['gradeLevel', 'academicYear']);
        $added = [];
        foreach ($this->activeEnrollments($classGroup) as $enrollment) {
            $student = $enrollment->student;
            if (! $student?->profile) {
                continue;
            }
            $rel = $this->originalPhotoRelativePath($student);
            if (! $rel || ! $disk->exists($rel)) {
                continue;
            }
            $fullName = trim(($student->profile->first_name ?? '').' '.($student->profile->last_name ?? ''));
            $entry = $this->photoExportFilename($student, $fullName, $rel);
            $zip->addFile($disk->path($rel), 'fotos/'.$entry);
            $added[] = $entry;
        }
        $zip->close();

        return ['path' => $zipPath, 'names' => $added];
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function activeEnrollments(ClassGroup $classGroup): Collection
    {
        return Enrollment::query()
            ->where('class_group_id', $classGroup->id)
            ->where('status', EnrollmentStatus::Active)
            ->with([
                'student.profile.address',
                'student.guardians.profile',
                'student.currentEnrollment.classGroup.gradeLevel:id,name',
                'student.currentEnrollment.classGroup:id,name,grade_level_id',
                'student.credentialTrackings' => function ($q) use ($classGroup) {
                    $q->where('academic_year_id', $classGroup->academic_year_id);
                },
                'student.workshops' => function ($q) use ($classGroup) {
                    $q->wherePivot('academic_year_id', $classGroup->academic_year_id);
                },
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{has_photo: bool, photo_filename: string, photo_url: ?string, photo_updated_at: ?string, photo_freshness: string}
     */
    private function photoMeta(Student $student, ClassGroup $classGroup): array
    {
        $rel = $this->originalPhotoRelativePath($student);
        $hasPhoto = (bool) $rel;
        $takenAt = null;
        if ($rel && Storage::disk('private')->exists($rel)) {
            $takenAt = Carbon::createFromTimestamp(Storage::disk('private')->lastModified($rel));
        }
        $fullName = trim(($student->profile?->first_name ?? '').' '.($student->profile?->last_name ?? ''));

        return [
            'has_photo' => $hasPhoto,
            'photo_filename' => $hasPhoto ? $this->photoExportFilename($student, $fullName, $rel) : '',
            'photo_url' => $hasPhoto ? $this->photoPaths->signedUrl($student, 'profile') : null,
            'photo_updated_at' => $takenAt?->toIso8601String(),
            'photo_freshness' => $this->resolveFreshness($takenAt, $classGroup->academicYear),
        ];
    }

    private function resolveFreshness(?Carbon $takenAt, ?AcademicYear $year): string
    {
        if (! $takenAt) {
            return 'missing';
        }

        $cycleStart = $this->cycleStart($year);
        if (! $cycleStart) {
            return 'unknown';
        }

        return $takenAt->gte($cycleStart) ? 'current' : 'stale';
    }

    private function cycleStart(?AcademicYear $year): ?Carbon
    {
        if (! $year) {
            return null;
        }
        if ($year->starts_on) {
            return Carbon::parse($year->starts_on)->startOfDay();
        }
        if ($year->year_start) {
            return Carbon::create((int) $year->year_start, 8, 1)->startOfDay();
        }

        return null;
    }

    private function photoExportFilename(Student $student, string $fullName, ?string $relativePath = null): string
    {
        $rel = $relativePath ?? $this->originalPhotoRelativePath($student);
        $ext = $rel ? strtolower(pathinfo($rel, PATHINFO_EXTENSION) ?: 'jpg') : 'jpg';
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $slug = Str::slug($fullName, '_');

        return $student->id.'_'.($slug !== '' ? $slug : 'alumno').'.'.$ext;
    }

    /**
     * @param  array{meta: array<string, mixed>, rows: array<int, array<string, mixed>>}  $bundle
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    private function credentialsMatrix(array $bundle): array
    {
        $headings = [
            'Nombre completo',
            'Taller',
            'CURP',
            'Dirección',
            'Tutor',
            'Teléfono',
            'Nombre archivo foto',
        ];

        $rows = [];
        foreach ($bundle['rows'] as $r) {
            $rows[] = [
                $r['full_name'],
                $r['workshop_names'],
                $r['curp'],
                $r['address'],
                $r['tutor_name'],
                $r['phone'],
                $r['photo_filename'] ?? '',
            ];
        }

        return [$headings, $rows];
    }

    /**
     * @param  array{meta: array<string, mixed>, rows: array<int, array<string, mixed>>}  $bundle
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    private function reportMatrix(array $bundle): array
    {
        $headings = [
            'ID alumno',
            'Folio credencial',
            'Nombre completo',
            'Grado',
            'Grupo',
            'Taller',
            'CURP',
            'Dirección',
            'Tutor',
            'Teléfono',
            'Nombre archivo foto',
            'Vigencia foto',
            'Fecha foto',
            'Tiene foto',
            'Datos completos',
            'Faltantes',
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
            $taken = $r['photo_updated_at']
                ? Carbon::parse($r['photo_updated_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i')
                : '';
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
                $this->freshnessLabel((string) $r['photo_freshness']),
                $taken,
                $this->yesNo((bool) $r['has_photo']),
                $this->yesNo((bool) $r['data_complete']),
                implode(', ', $r['data_missing'] ?? []),
                $this->yesNo((bool) $t['credential_printed']),
                $this->yesNo((bool) $t['nfc_ready']),
                $this->yesNo((bool) $t['ready_to_deliver']),
                $this->yesNo((bool) $t['paid']),
                $this->yesNo((bool) $t['delivered']),
                $this->yesNo((bool) $t['lost']),
                $r['replacement_label'],
            ];
        }

        return [$headings, $rows];
    }

    private function freshnessLabel(string $freshness): string
    {
        return match ($freshness) {
            'current' => 'Ciclo actual',
            'stale' => 'Ciclo anterior',
            'missing' => 'Sin foto',
            default => 'Desconocida',
        };
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }

    private function viewerCanSeePhotos(): bool
    {
        $user = auth()->user();

        return $user
            && ($user->can('view student photos') || $user->can('manage student photos'));
    }
}
