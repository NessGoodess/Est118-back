<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Models\GeneralAttendance;
use App\Models\RecentReading;
use App\Models\Student;
use App\Services\StudentPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeneralAttendanceController extends Controller
{
    public function __construct(
        private readonly StudentPhotoPathService $photoPathService
    ) {}

    /**
     * List attendance records for history (frontend panel).
     */
    public function index(Request $request)
    {
        try {
            $limit = min($request->integer('limit', 50), 100);

            $attendances = GeneralAttendance::query()
                ->whereNotNull('scanned_at')
                ->where('source', AttendanceSource::NFC)
                ->with([
                    'student:id,credential_id,profile_id',
                    'student.profile:id,first_name,last_name,profile_picture,gender,updated_at',
                    'student.currentGroup.gradeLevel:id,name',
                    'student.currentGroup',
                ])
                ->orderByDesc('scanned_at')
                ->limit($limit)
                ->get();

            $list = $attendances->map(function ($attendance) {
                $student = $attendance->student;
                $grade = $student->currentGroup?->gradeLevel?->name;
                $group = $student->currentGroup?->name;

                return [
                    'id' => $student->id,
                    'credential_id' => $student->credential_id,
                    'name' => trim(
                        collect([
                            $student->profile?->first_name,
                            $student->profile?->last_name,
                        ])->filter()->join(' ')
                    ),
                    'photo_url' => $this->photoPathService->signedUrl($student, 'profile'),
                    'gender' => $student->profile?->gender,
                    'grade' => $grade,
                    'group' => $group,
                    'registered_at' => $attendance->scanned_at?->toIso8601String(),
                ];
            })->values()->all();

            return response()->json($list);
        } catch (\Exception $e) {
            Log::error('GeneralAttendance index error', ['error' => $e->getMessage()]);

            return response()->json([], 500);
        }
    }

    /**
     * Get the last (most recent) attendance record.
     */
    public function getLastAttendance()
    {
        try {
            $attendance = GeneralAttendance::query()
                ->whereNotNull('scanned_at')
                ->where('source', AttendanceSource::NFC)
                ->with([
                    'student:id,credential_id,profile_id',
                    'student.profile:id,first_name,last_name,profile_picture,gender,updated_at',
                    'student.currentGroup.gradeLevel:id,name',
                    'student.currentGroup',
                ])
                ->orderByDesc('scanned_at')
                ->first();

            if (! $attendance) {
                return response()->json(null);
            }

            $student = $attendance->student;
            $firstName = $student->profile?->first_name;
            $lastName = $student->profile?->last_name;

            return response()->json([
                'id' => $attendance->student_id,
                'credential_id' => $student->credential_id,
                'name' => trim($firstName . ' ' . $lastName),
                'photo_url' => $this->photoPathService->signedUrl($student, 'profile'),
                'gender' => $student->profile?->gender,
                'grade' => optional($student->currentGroup?->gradeLevel)->name,
                'group' => optional($student->currentGroup)->name,
                'registered_at' => $attendance->scanned_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('GeneralAttendance getLastAttendance error', ['error' => $e->getMessage()]);

            return response()->json(null);
        }
    }

    /**
     * Get recent credential readings for the panel.
     */
    public function recentReadings(Request $request)
    {
        try {
            $limit = min($request->integer('limit', 20), 50);
            $date = $request->date ?? now()->toDateString();

            $readings = RecentReading::query()
                ->whereDate('read_at', $date)
                ->with(['student.profile', 'student.currentGroup.gradeLevel', 'student.currentGroup'])
                ->orderByDesc('read_at')
                ->limit($limit)
                ->get();

            $list = $readings->map(function ($r) {
                /** @var Student $student */
                $student = $r->student;
                $grade = $student->currentGroup?->gradeLevel?->name;
                $group = $student->currentGroup?->name;

                return [
                    'id' => $r->id,
                    'student_id' => $student->id,
                    'name' => trim(($student->profile?->first_name ?? '') . ' ' . ($student->profile?->last_name ?? '')),
                    'photo_url' => $this->photoPathService->signedUrl($student, 'profile'),
                    'gender' => $student->profile?->gender,
                    'grade' => $grade,
                    'group' => $group,
                    'event' => $r->event,
                    'message' => $r->message,
                    'read_at' => $r->read_at?->toIso8601String(),
                ];
            })->values()->all();

            return response()->json($list);
        } catch (\Exception $e) {
            Log::error('GeneralAttendance recentReadings error', ['error' => $e->getMessage()]);

            return response()->json([]);
        }
    }
}
