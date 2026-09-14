<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\WorkshopEnrollment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\JsonResponse;

class AttendanceController extends Controller
{
    use AuthorizesRequests;

    public function getClassAttendance($scheduleId, $date): JsonResponse
    {
        $schedule = Schedule::with([
            'schoolClass.subject',
            'schoolClass.teacher.profile',
            'schoolClass.classGroup.enrollments.student.profile',
            'workshop:id,name,code',
            'classroom',
        ])->findOrFail($scheduleId);

        $this->authorize('view', $schedule);

        $enrollments = $schedule->schoolClass->classGroup->enrollments
            ->filter(fn ($enrollment) => $enrollment->status === EnrollmentStatus::Active);
        $groupEnrollmentCount = $enrollments->count();

        $yearId = (int) ($schedule->schoolClass?->classGroup?->academic_year_id ?? 0);
        $isWorkshop = $schedule->schedule_type === 'workshop' && (int) $schedule->workshop_id > 0 && $yearId > 0;
        if ($isWorkshop) {
            $assignedIds = WorkshopEnrollment::query()
                ->where('academic_year_id', $yearId)
                ->where('workshop_id', $schedule->workshop_id)
                ->where('status', WorkshopEnrollmentStatus::Assigned->value)
                ->pluck('student_id');
            $enrollments = $enrollments->whereIn('student_id', $assignedIds->all());
        }

        $studentIds = $enrollments->pluck('student_id');

        $attendances = Attendance::whereIn('student_id', $studentIds)
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', $date)
            ->get()
            ->keyBy('student_id');

        $students = $enrollments->map(function ($enrollment) use ($attendances) {
            $student = $enrollment->student;
            $existingAttendance = $attendances->get($student->id);

            return [
                'student_id' => $student->id,
                'last_name' => $student->profile->last_name,
                'name' => $student->profile->first_name,
                'current_attendance' => $existingAttendance ? [
                    'status' => $existingAttendance->status,
                    'notes' => $existingAttendance->notes,
                    'recorded_at' => $existingAttendance->created_at,
                ] : null,
            ];
        })->values();

        $totalStudents = $students->count();

        if ($totalStudents === 0) {
            $completedDates = [];
            $incompleteDates = [];
        } else {
            $completedDates = Attendance::where('schedule_id', $scheduleId)
                ->select('attendance_date')
                ->groupBy('attendance_date')
                ->havingRaw('COUNT(DISTINCT student_id) = ?', [$totalStudents])
                ->pluck('attendance_date')
                ->toArray();

            $allDates = Attendance::where('schedule_id', $scheduleId)
                ->distinct()
                ->pluck('attendance_date')
                ->toArray();

            $incompleteDates = array_values(array_diff($allDates, $completedDates));
        }

        $subject = $schedule->schoolClass?->subject?->name;
        if ($schedule->schedule_type === 'workshop' && $schedule->workshop?->name) {
            $subject = $schedule->workshop->name;
        }

        return response()->json([
            'success' => true,
            'schedule_info' => [
                'schedule_id' => $schedule->id,
                'subject' => $subject,
                'class_group' => $schedule->schoolClass?->classGroup?->name,
                'schedule_type' => $schedule->schedule_type,
                'workshop_name' => $schedule->workshop?->name,
                'day' => $schedule->day,
                'time' => $schedule->start_time.' - '.$schedule->end_time,
                'classroom' => $schedule->classroom ? $schedule->classroom->name : 'Sin aula asignada',
                'attendance_date' => $date,
            ],
            'markedDates' => [
                'completedDates' => $completedDates,
                'incompleteDates' => $incompleteDates,
            ],
            'students' => $students,
            'empty_reason' => $students->isEmpty()
                ? ($isWorkshop && $groupEnrollmentCount > 0
                    ? 'no_workshop_assignments'
                    : 'no_group_enrollments')
                : null,
        ]);
    }

    public function recordAttendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'schedule_id' => ['required', 'integer', 'exists:schedules,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,late,excused'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->authorize('create', Attendance::class);
        [$schedule, $schoolClass, $yearId, $allowedIds] = $this->scheduleRoster((int) $data['schedule_id']);
        $this->authorize('view', $schedule);
        if (! in_array((int) $data['student_id'], $allowedIds, true)) {
            return response()->json(['message' => 'El alumno no está en la lista de este horario.'], 422);
        }

        $attendance = $this->upsertAttendance(
            $schedule,
            $schoolClass,
            $yearId,
            (int) $data['student_id'],
            $data['date'],
            $data['status'],
            $data['notes'] ?? null,
        );

        return response()->json([
            'success' => true,
            'attendance' => $attendance,
        ]);
    }

    public function recordAttendanceBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'schedule_id' => ['required', 'integer', 'exists:schedules,id'],
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.notes' => ['nullable', 'string'],
        ]);

        $this->authorize('create', Attendance::class);
        [$schedule, $schoolClass, $yearId, $allowedIds] = $this->scheduleRoster((int) $data['schedule_id']);
        $this->authorize('view', $schedule);
        $allowed = array_flip($allowedIds);

        $saved = 0;
        DB::transaction(function () use ($data, $schedule, $schoolClass, $yearId, $allowed, &$saved): void {
            foreach ($data['records'] as $row) {
                $studentId = (int) $row['student_id'];
                if (! isset($allowed[$studentId])) {
                    continue;
                }
                $this->upsertAttendance(
                    $schedule,
                    $schoolClass,
                    $yearId,
                    $studentId,
                    $data['date'],
                    $row['status'],
                    $row['notes'] ?? null,
                );
                $saved++;
            }
        });

        return response()->json([
            'success' => true,
            'saved' => $saved,
        ]);
    }

    /**
     * @return array{0: Schedule, 1: SchoolClass, 2: int, 3: list<int>}
     */
    private function scheduleRoster(int $scheduleId): array
    {
        $schedule = Schedule::query()
            ->with('schoolClass.classGroup.enrollments')
            ->findOrFail($scheduleId);
        $schoolClass = $schedule->schoolClass;
        $yearId = (int) ($schoolClass?->classGroup?->academic_year_id ?? 0);
        if (! $schoolClass || $yearId <= 0) {
            abort(response()->json(['message' => 'El horario no tiene grupo o ciclo escolar.'], 422));
        }

        $enrollments = $schoolClass->classGroup->enrollments
            ->filter(fn ($enrollment) => $enrollment->status === EnrollmentStatus::Active);

        if ($schedule->schedule_type === 'workshop' && $schedule->workshop_id) {
            $assignedIds = WorkshopEnrollment::query()
                ->where('academic_year_id', $yearId)
                ->where('workshop_id', $schedule->workshop_id)
                ->where('status', WorkshopEnrollmentStatus::Assigned->value)
                ->pluck('student_id')
                ->all();
            $enrollments = $enrollments->whereIn('student_id', $assignedIds);
        }

        return [$schedule, $schoolClass, $yearId, $enrollments->pluck('student_id')->map(fn ($id) => (int) $id)->all()];
    }

    private function upsertAttendance(
        Schedule $schedule,
        SchoolClass $schoolClass,
        int $yearId,
        int $studentId,
        string $date,
        string $status,
        ?string $notes,
    ): Attendance {
        return Attendance::query()->updateOrCreate(
            [
                'schedule_id' => $schedule->id,
                'student_id' => $studentId,
                'academic_year_id' => $yearId,
                'attendance_date' => $date,
            ],
            [
                'school_class_id' => $schoolClass->id,
                'status' => $status,
                'notes' => $notes,
            ]
        );
    }
}
