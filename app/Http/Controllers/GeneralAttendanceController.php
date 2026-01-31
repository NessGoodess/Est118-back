<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Models\GeneralAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeneralAttendanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->integer('limit', 50);

            $attendances = GeneralAttendance::query()
                ->whereNotNull('scanned_at')
                ->where('source', AttendanceSource::NFC)
                ->with([
                    'student:id,credential_id,profile_id',
                    'student.profile:id,first_name,last_name,profile_picture',
                    'student.currentGroup.gradeLevel:id,name',
                    'student.currentGroup',
                ])
                ->orderByDesc('scanned_at')
                ->limit($limit)
                ->first();

            $student = $attendances->student;

            $grade = $student->currentGroup?->gradeLevel?->name;
            $group = $student->currentGroup?->name;
            $photo = $student->profile?->profile_picture;

            $photoPath = ($grade && $group && $photo)
                ? 'photos/students/'.rawurlencode($grade).'/'.rawurlencode($group).'/'.rawurlencode($photo)
                : 'photos/students/default.png';

            return [
                'id' => $student->id,
                'credential_id' => $student->credential_id,
                'name' => trim(
                    collect([
                        $student->profile?->first_name,
                        $student->profile?->last_name,
                    ])->filter()->join(' ')
                ),
                'photo_url' => $photoPath,
                'grade' => $grade,
                'group' => $group,
                'registered_at' => $attendances->scanned_at?->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error($e);

            return response()->json(null);
        }
    }

    public function getLastAttendance()
    {
        try {
            $attendance = GeneralAttendance::query()
                ->whereNotNull('scanned_at')
                ->where('source', AttendanceSource::NFC)
                ->with([
                    'student:id,credential_id,profile_id',
                    'student.profile:id,first_name,last_name,profile_picture',
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
            $grade = $student->currentGroup?->gradeLevel?->name;
            $group = $student->currentGroup?->name;
            $photo = $student->profile?->profile_picture;

            $photoPath = ($grade && $group && $photo)
                ? 'photos/students/'.rawurlencode($grade).'/'.rawurlencode($group).'/'.rawurlencode($photo)
                : 'photos/students/default.png';

            return response()->json([
                'id' => $attendance->id,
                'scanned_at' => $attendance->scanned_at,
                'student' => [
                    'id' => $student->id,
                    'credential_id' => $student->credential_id,
                    'name' => trim($firstName.' '.$lastName),
                    'photo_url' => $photoPath,
                    'grade' => optional($student->currentGroup?->gradeLevel)->name,
                    'group' => optional($student->currentGroup)->name,
                    'registered_at' => $attendance->scanned_at?->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error($e);

            return response()->json(null);
        }
    }
}
