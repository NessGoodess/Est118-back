<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Models\Schedule;
use Symfony\Component\HttpFoundation\JsonResponse;

class AttendanceController extends Controller
{
    public function getClassAttendance($scheduleId, $date): JsonResponse
    {
        $schedule = Schedule::with([
            'schoolClass.subject',
            'schoolClass.teacher.profile',
            'schoolClass.classGroup.enrollments.student.profile',
            'classroom'
        ])->findOrFail($scheduleId);

        $enrollments = $schedule->schoolClass->classGroup->enrollments
            ->where('status', 'active');

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
                    'recorded_at' => $existingAttendance->created_at
                ] : null
            ];
        });

        $schoolClass =$schedule->schoolClass;
        $totalStudents = $schoolClass->students()->count();

        if ($totalStudents === 0) {
            $completedDates = [];
            $incompleteDates = [];
        } else {
            $completedDates = Attendance::where('school_class_id', $schoolClass->id)
                ->select('attendance_date')
                ->groupBy('attendance_date')
                ->havingRaw('COUNT(DISTINCT student_id) = ?', [$totalStudents])
                ->pluck('attendance_date')
                ->toArray();

            $allDates = Attendance::where('school_class_id', $schoolClass->id)
                ->distinct()
                ->pluck('attendance_date')
                ->toArray();

            $incompleteDates = array_values(array_diff($allDates, $completedDates));
        }

        return response()->json([
            'success' => true,
            'schedule_info' => [
                'schedule_id' => $schedule->id,
                'subject' => $schedule->schoolClass->subject->name,
                'class_group' => $schedule->schoolClass->classGroup->name,
                'day' => $schedule->day,
                'time' => $schedule->start_time . ' - ' . $schedule->end_time,
                'classroom' => $schedule->classroom ? $schedule->classroom->name : 'Sin aula asignada',
                'attendance_date' => $date
            ],
            'markedDates' => [
                'completedDates' => $completedDates,
                'incompleteDates' => $incompleteDates,
            ],
            'students' => $students
        ]);
    }
}
