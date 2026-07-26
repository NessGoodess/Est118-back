<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Http\Resources\AttendanceStudentResource;
use App\Http\Resources\RecentReadingResource;
use App\Models\GeneralAttendance;
use App\Models\RecentReading;
use App\Services\DailyGeneralAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GeneralAttendanceController extends Controller
{
    public function __construct(
        private readonly DailyGeneralAttendanceService $dailyAttendance
    ) {}

    /**
     * Daily consolidated roster with effective attendance status and metrics.
     */
    public function daily(Request $request)
    {
        try {
            $validated = $request->validate([
                'date' => ['nullable', 'date_format:Y-m-d'],
            ]);

            $date = $validated['date'] ?? now()->toDateString();
            $data = $this->dailyAttendance->forDate($date);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('GeneralAttendance daily error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener la asistencia del día.',
            ], 500);
        }
    }

    /**
     * Lightweight daily statuses. Use after the full roster is cached.
     */
    public function dailyStatuses(Request $request)
    {
        try {
            $validated = $request->validate([
                'date' => ['nullable', 'date_format:Y-m-d'],
            ]);

            $date = $validated['date'] ?? now()->toDateString();
            $data = $this->dailyAttendance->statusesForDate($date);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('GeneralAttendance dailyStatuses error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudieron obtener los estados de asistencia.',
            ], 500);
        }
    }

    /**
     * Get all attendance records for the given date.
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

            return AttendanceStudentResource::collection($attendances);
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

            return new AttendanceStudentResource($attendance);
        } catch (\Exception $e) {
            Log::error('GeneralAttendance getLastAttendance error', ['error' => $e->getMessage()]);

            return response()->json(null);
        }
    }

    /**
     * Recent NFC read events for the live panel.
     */
    public function recentReadings(Request $request)
    {
        try {
            $limit = min($request->integer('limit', 20), 50);
            $date = $request->date ?? now()->toDateString();

            $readings = RecentReading::query()
                ->whereDate('read_at', $date)
                ->with([
                    'student:id,credential_id,profile_id',
                    'student.profile:id,first_name,last_name,profile_picture,gender,updated_at',
                    'student.currentGroup.gradeLevel:id,name',
                    'student.currentGroup',
                ])
                ->orderByDesc('read_at')
                ->limit($limit)
                ->get();

            return RecentReadingResource::collection($readings);
        } catch (\Exception $e) {
            Log::error('GeneralAttendance recentReadings error', ['error' => $e->getMessage()]);

            return response()->json([]);
        }
    }
}
