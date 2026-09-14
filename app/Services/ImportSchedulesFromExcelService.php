<?php

namespace App\Services;

use App\Enums\AdmissionWorkshop;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Workshop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class ImportSchedulesFromExcelService
{
    /** @var list<string> */
    private const DAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];

    private const GRID_MAX_ROW = 50;

    private const GRID_MAX_COL = 12;

    /** @var array<string, string> */
    private const SUBJECT_ALIASES = [
        'formcivyetica' => 'formacioncivicayetica',
        'formacioncivicaetica' => 'formacioncivicayetica',
        'educfisica' => 'educacionfisica',
        'edufisica' => 'educacionfisica',
        'matematica' => 'matematicas',
        'epanol' => 'espanol',
    ];

    /** @var list<string> */
    private array $unmatchedSubjects = [];

    /** @var list<string> */
    private array $workshopSheets = [];

    private int $teachersAssigned = 0;

    /** @var Collection<int, Teacher>|null */
    private ?Collection $teachersCache = null;

    /**
     * @return array<string, mixed>
     */
    public function import(
        int $academicYearId,
        string $gruposPath,
        ?string $docentesPath = null,
        bool $dryRun = true,
    ): array {
        $year = AcademicYear::findOrFail($academicYearId);
        $tecnologia = Subject::query()->where('code', 'TECNOLOGIA')->first();
        if (! $tecnologia) {
            throw new RuntimeException('Falta la materia Tecnología (code=TECNOLOGIA).');
        }

        $subjects = Subject::all();
        $workshops = Workshop::query()->where('is_active', true)->get();
        $this->unmatchedSubjects = [];
        $this->workshopSheets = [];
        $this->teachersAssigned = 0;
        $this->teachersCache = null;

        $run = function () use ($gruposPath, $docentesPath, $year, $subjects, $workshops, $tecnologia, $dryRun): array {
            $planned = [];

            if (is_file($gruposPath)) {
                $planned = array_merge($planned, $this->parseGrupos($gruposPath, $year, $subjects, $tecnologia));
            }

            if ($docentesPath && is_file($docentesPath)) {
                $planned = array_merge($planned, $this->parseDocentes($docentesPath, $year, $workshops, $tecnologia));
            }

            $created = 0;
            $skipped = 0;
            foreach ($planned as $row) {
                $exists = Schedule::query()
                    ->where('school_class_id', $row['school_class_id'])
                    ->where('workshop_id', $row['workshop_id'])
                    ->where('day', $row['day'])
                    ->where('start_time', $row['start_time'])
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                if (! $dryRun) {
                    Schedule::create($row);
                    $created++;
                }
            }

            if ($docentesPath && is_file($docentesPath)) {
                $this->assignRegularTeachersFromDocentes($docentesPath, $year, $workshops);
            }

            return [
                'dry_run' => $dryRun,
                'planned' => count($planned),
                'created' => $created,
                'skipped' => $skipped,
                'preview' => array_slice($planned, 0, 40),
                'workshop_sheets' => $this->workshopSheets,
                'unmatched_subjects' => array_values(array_unique($this->unmatchedSubjects)),
                'teachers_assigned' => $this->teachersAssigned,
            ];
        };

        if ($dryRun) {
            $summary = null;
            try {
                DB::transaction(function () use ($run, &$summary): void {
                    $summary = $run();
                    throw new RuntimeException('__DRY_RUN_ROLLBACK__');
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== '__DRY_RUN_ROLLBACK__') {
                    throw $e;
                }
            }

            return $summary ?? [
                'dry_run' => true,
                'planned' => 0,
                'created' => 0,
                'skipped' => 0,
                'preview' => [],
                'workshop_sheets' => [],
                'unmatched_subjects' => [],
                'teachers_assigned' => 0,
            ];
        }

        return DB::transaction($run);
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     * @return list<array<string, mixed>>
     */
    private function parseGrupos(string $path, AcademicYear $year, Collection $subjects, Subject $tecnologia): array
    {
        $spreadsheet = $this->loadSpreadsheet($path);
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $group = $this->resolveGroupFromTitle($sheet->getTitle(), $year);
            if (! $group) {
                continue;
            }

            $grid = $this->readGrid($sheet);
            foreach ($grid as $cell) {
                $subject = $this->matchSubject($cell['value'], $subjects, $tecnologia, $group->gradeLevel?->name);
                if (! $subject) {
                    continue;
                }

                $isTech = $subject->id === $tecnologia->id
                    || AdmissionWorkshop::normalize($subject->name) === AdmissionWorkshop::normalize('Tecnología');

                $schoolClass = SchoolClass::query()->firstOrCreate(
                    [
                        'subject_id' => $subject->id,
                        'class_group_id' => $group->id,
                    ],
                    ['teacher_id' => null]
                );

                if ($isTech) {
                    if ($schoolClass->teacher_id !== null) {
                        $schoolClass->teacher_id = null;
                        $schoolClass->save();
                    }
                    continue;
                }

                $rows[] = [
                    'school_class_id' => $schoolClass->id,
                    'workshop_id' => null,
                    'classroom_id' => null,
                    'teacher_id' => $schoolClass->teacher_id,
                    'day' => $cell['day'],
                    'start_time' => $cell['start_time'],
                    'end_time' => $cell['end_time'],
                    'schedule_type' => 'regular',
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();

        return $rows;
    }

    /**
     * @param  Collection<int, Workshop>  $workshops
     * @return list<array<string, mixed>>
     */
    private function parseDocentes(string $path, AcademicYear $year, Collection $workshops, Subject $tecnologia): array
    {
        $spreadsheet = $this->loadSpreadsheet($path);
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $teacher = $this->matchTeacher($sheet->getTitle());
            $workshop = $this->detectWorkshop($sheet, $workshops);
            if (! $workshop) {
                continue;
            }

            $this->workshopSheets[] = trim($sheet->getTitle()).' → '.$workshop->name;

            $classroom = $this->matchClassroom($workshop);
            $grid = $this->readGrid($sheet);

            foreach ($grid as $cell) {
                $letters = $this->parseLetterBlock($cell['value']);
                if ($letters === null) {
                    continue;
                }

                foreach ($letters['letters'] as $letter) {
                    $group = ClassGroup::query()
                        ->where('academic_year_id', $year->id)
                        ->where('name', $letter)
                        ->whereHas('gradeLevel', fn ($q) => $q->where('name', $letters['grade'].'°'))
                        ->first();
                    if (! $group) {
                        continue;
                    }

                    $schoolClass = SchoolClass::query()->firstOrCreate(
                        [
                            'subject_id' => $tecnologia->id,
                            'class_group_id' => $group->id,
                        ],
                        ['teacher_id' => null]
                    );

                    $rows[] = [
                        'school_class_id' => $schoolClass->id,
                        'workshop_id' => $workshop->id,
                        'classroom_id' => $classroom?->id,
                        'teacher_id' => $teacher?->id,
                        'day' => $cell['day'],
                        'start_time' => $cell['start_time'],
                        'end_time' => $cell['end_time'],
                        'schedule_type' => 'workshop',
                    ];
                }
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();

        return $rows;
    }

    /**
     * @param  Collection<int, Workshop>  $workshops
     */
    private function assignRegularTeachersFromDocentes(string $path, AcademicYear $year, Collection $workshops): void
    {
        $spreadsheet = $this->loadSpreadsheet($path);
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($this->detectWorkshop($sheet, $workshops)) {
                continue;
            }
            $teacher = $this->matchTeacher($sheet->getTitle());
            if (! $teacher) {
                continue;
            }
            $this->assignRegularTeacherSlots($sheet, $teacher, $year);
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();
    }

    private function assignRegularTeacherSlots(Worksheet $sheet, Teacher $teacher, AcademicYear $year): void
    {
        foreach ($this->readGrid($sheet) as $cell) {
            $letters = $this->parseLetterBlock($cell['value']);
            if ($letters === null) {
                continue;
            }

            foreach ($letters['letters'] as $letter) {
                $group = ClassGroup::query()
                    ->where('academic_year_id', $year->id)
                    ->where('name', $letter)
                    ->whereHas('gradeLevel', fn ($q) => $q->where('name', $letters['grade'].'°'))
                    ->first();
                if (! $group) {
                    continue;
                }

                $schedules = Schedule::query()
                    ->with('schoolClass')
                    ->where('schedule_type', 'regular')
                    ->where('day', $cell['day'])
                    ->where('start_time', $cell['start_time'])
                    ->whereHas('schoolClass', fn ($q) => $q->where('class_group_id', $group->id))
                    ->get();

                foreach ($schedules as $schedule) {
                    if ((int) $schedule->teacher_id !== (int) $teacher->id) {
                        $schedule->teacher_id = $teacher->id;
                        $schedule->save();
                        $this->teachersAssigned++;
                    }
                    if ($schedule->schoolClass && $schedule->schoolClass->teacher_id === null) {
                        $schedule->schoolClass->teacher_id = $teacher->id;
                        $schedule->schoolClass->save();
                    }
                }
            }
        }
    }

    /**
     * Lee celdas sin estilos ni imágenes embebidas (los Excel institucionales traen fotos).
     */
    private function loadSpreadsheet(string $path): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setIncludeCharts(false);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if (method_exists($reader, 'setReadFilter')) {
            $maxRow = self::GRID_MAX_ROW;
            $maxCol = self::GRID_MAX_COL;
            $reader->setReadFilter(new class($maxRow, $maxCol) implements IReadFilter
            {
                public function __construct(private int $maxRow, private int $maxCol) {}

                public function readCell($columnAddress, $row, $worksheetName = '')
                {
                    if ($row > $this->maxRow) {
                        return false;
                    }

                    return Coordinate::columnIndexFromString((string) $columnAddress) <= $this->maxCol;
                }
            });
        }

        return $reader->load($path);
    }

    /**
     * @return list<array{day:string,start_time:string,end_time:string,value:string}>
     */
    private function readGrid(Worksheet $sheet): array
    {
        $highestRow = min((int) $sheet->getHighestDataRow(), self::GRID_MAX_ROW);
        $highestCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'), self::GRID_MAX_COL);

        $headerRow = 1;
        $dayByCol = [];
        $horaCols = [];
        for ($row = 1; $row <= min(25, $highestRow); $row++) {
            $found = [];
            $horas = [];
            for ($col = 1; $col <= $highestCol; $col++) {
                $raw = trim($this->cell($sheet, $col, $row));
                if (AdmissionWorkshop::normalize($raw) === 'hora') {
                    $horas[] = $col;
                }
                $day = $this->matchDay($raw);
                if ($day) {
                    $found[$col] = $day;
                }
            }
            if (count($found) >= 3) {
                $headerRow = $row;
                $dayByCol = $found;
                $horaCols = $horas;
                break;
            }
        }

        if ($dayByCol === []) {
            return [];
        }

        $timeColByDayCol = [];
        foreach ($dayByCol as $col => $day) {
            $timeCol = 1;
            foreach ($horaCols as $horaCol) {
                if ($horaCol < $col) {
                    $timeCol = $horaCol;
                }
            }
            $timeColByDayCol[$col] = $timeCol;
        }

        $cells = [];
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            foreach ($dayByCol as $col => $day) {
                $value = trim($this->cell($sheet, $col, $row));
                if ($value === '') {
                    continue;
                }
                $times = $this->parseTimeRange(trim($this->cell($sheet, $timeColByDayCol[$col], $row)));
                if (! $times) {
                    continue;
                }
                $cells[] = [
                    'day' => $day,
                    'start_time' => $times[0],
                    'end_time' => $times[1],
                    'value' => $value,
                ];
            }
        }

        return $cells;
    }

    private function resolveGroupFromTitle(string $title, AcademicYear $year): ?ClassGroup
    {
        if (! preg_match('/^\s*([123])\s*[°º]?\s*([A-Ha-h])\s*$/u', $title, $m)) {
            return null;
        }

        $gradeName = $m[1].'°';
        $letter = strtoupper($m[2]);

        return ClassGroup::query()
            ->with('gradeLevel')
            ->where('academic_year_id', $year->id)
            ->where('name', $letter)
            ->whereHas('gradeLevel', fn ($q) => $q->where('name', $gradeName))
            ->first();
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     */
    private function matchSubject(string $value, Collection $subjects, Subject $tecnologia, ?string $gradeName = null): ?Subject
    {
        $normalized = AdmissionWorkshop::normalize($value);
        if ($normalized === '' || $this->isIgnorableCell($normalized) || $this->parseLetterBlock($value) !== null) {
            return null;
        }

        if (str_contains($normalized, 'tecnolog')) {
            return $tecnologia;
        }

        $normalized = self::SUBJECT_ALIASES[$normalized] ?? $normalized;

        if ($normalized === 'ciencias') {
            $ciencias = $this->matchCiencias($subjects, $gradeName);
            if ($ciencias) {
                return $ciencias;
            }
        }

        foreach ($subjects as $subject) {
            if (AdmissionWorkshop::normalize($subject->name) === $normalized) {
                return $subject;
            }
        }

        foreach ($subjects as $subject) {
            $name = AdmissionWorkshop::normalize($subject->name);
            if ($name !== '' && (str_contains($normalized, $name) || str_contains($name, $normalized))) {
                return $subject;
            }
        }

        $this->unmatchedSubjects[] = $value;

        return null;
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     */
    private function matchCiencias(Collection $subjects, ?string $gradeName): ?Subject
    {
        $want = match (true) {
            is_string($gradeName) && str_starts_with($gradeName, '1') => 'cienciasibiologia',
            is_string($gradeName) && str_starts_with($gradeName, '2') => 'cienciasiifisica',
            is_string($gradeName) && str_starts_with($gradeName, '3') => 'cienciasiiiquimica',
            default => null,
        };

        if ($want) {
            foreach ($subjects as $subject) {
                if (AdmissionWorkshop::normalize($subject->name) === $want) {
                    return $subject;
                }
            }
        }

        return $subjects->first(fn (Subject $subject) => str_starts_with(AdmissionWorkshop::normalize($subject->name), 'ciencias'));
    }

    private function isIgnorableCell(string $normalized): bool
    {
        foreach (['receso', 'honores', 'observaciones', 'comision', 'atencionapadres', 'recibio'] as $skip) {
            if ($normalized === $skip || str_starts_with($normalized, $skip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Workshop>  $workshops
     */
    private function detectWorkshop(Worksheet $sheet, Collection $workshops): ?Workshop
    {
        $haystack = $sheet->getTitle();
        for ($row = 1; $row <= 20; $row++) {
            for ($col = 1; $col <= self::GRID_MAX_COL; $col++) {
                $haystack .= ' '.$this->cell($sheet, $col, $row);
            }
        }
        $normalized = AdmissionWorkshop::normalize($haystack);

        foreach ($workshops as $workshop) {
            $name = AdmissionWorkshop::normalize($workshop->name);
            if ($name !== '' && str_contains($normalized, $name)) {
                return $workshop;
            }
            if ($workshop->code && str_contains($normalized, AdmissionWorkshop::normalize($workshop->code))) {
                return $workshop;
            }
        }

        $enum = AdmissionWorkshop::fromName($haystack);

        return $enum
            ? $workshops->firstWhere('code', $enum->code())
            : null;
    }

    /**
     * @return array{grade:string,letters:list<string>}|null
     */
    private function parseLetterBlock(string $value): ?array
    {
        if (! preg_match('/([123])\s*[°º]?\s*([A-Ha-h](?:[\s,.-]*[A-Ha-h])*)/u', $value, $m)) {
            return null;
        }

        preg_match_all('/[A-Ha-h]/u', $m[2], $letters);

        $unique = array_values(array_unique(array_map('strtoupper', $letters[0] ?? [])));
        if ($unique === []) {
            return null;
        }

        return [
            'grade' => $m[1],
            'letters' => $unique,
        ];
    }

    private function matchDay(string $value): ?string
    {
        $normalized = AdmissionWorkshop::normalize($value);
        foreach (self::DAYS as $day) {
            if (AdmissionWorkshop::normalize($day) === $normalized) {
                return $day;
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseTimeRange(string $value): ?array
    {
        if (preg_match('/(\d{1,2}:\d{2})\s*[-–a]\s*(\d{1,2}:\d{2})/u', $value, $m)) {
            return [$this->normalizeTime($m[1]), $this->normalizeTime($m[2])];
        }

        return null;
    }

    private function normalizeTime(string $time): string
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '00');

        return sprintf('%02d:%02d:00', (int) $h, (int) $m);
    }

    private function matchTeacher(string $sheetTitle): ?Teacher
    {
        $needle = AdmissionWorkshop::normalize($sheetTitle);
        if ($needle === '') {
            return null;
        }

        $this->teachersCache ??= Teacher::query()->with('profile')->get();
        $firstNameHits = [];
        $startsWithHits = [];

        foreach ($this->teachersCache as $teacher) {
            $first = AdmissionWorkshop::normalize($teacher->profile?->first_name ?? '');
            $last = AdmissionWorkshop::normalize($teacher->profile?->last_name ?? '');
            $full = $first.$last;
            if ($full !== '' && $full === $needle) {
                return $teacher;
            }
            if ($first !== '' && $first === $needle) {
                $firstNameHits[] = $teacher;
            }
            if ($full !== '' && str_starts_with($full, $needle)) {
                $startsWithHits[] = $teacher;
            }
        }

        if (count($firstNameHits) === 1) {
            return $firstNameHits[0];
        }
        if (count($startsWithHits) === 1) {
            return $startsWithHits[0];
        }

        return $firstNameHits[0] ?? $startsWithHits[0] ?? null;
    }

    private function matchClassroom(Workshop $workshop): ?Classroom
    {
        $needle = AdmissionWorkshop::normalize($workshop->name);

        return Classroom::query()->get()->first(function (Classroom $room) use ($needle) {
            return str_contains(AdmissionWorkshop::normalize($room->name), $needle);
        });
    }

    private function cell(Worksheet $sheet, int $col, int $row): string
    {
        $coord = Coordinate::stringFromColumnIndex($col).$row;

        return (string) $sheet->getCell($coord)->getFormattedValue();
    }
}
