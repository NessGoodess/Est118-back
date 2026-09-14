<?php

namespace App\Http\Controllers;

use App\Enums\TeacherStatus;
use App\Models\ClassGroup;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Schedule::class);

        /** @var User $user */
        $user = $request->user();
        $scope = $user->scheduleScope();
        $sessionTeacherId = $user->linkedTeacherId();

        $query = Schedule::query()
            ->with([
                'schoolClass.subject:id,name,code',
                'schoolClass.classGroup:id,name,grade_level_id',
                'schoolClass.classGroup.gradeLevel:id,name',
                'schoolClass.teacher:id',
                'workshop:id,name,code',
                'teacher:id',
                'classroom:id,name',
            ])
            ->orderBy('day')
            ->orderBy('start_time');

        $filterTeacherId = $request->filled('teacher_id') ? (int) $request->integer('teacher_id') : null;
        $filterGroupId = $request->filled('class_group_id') ? (int) $request->integer('class_group_id') : null;
        $filterDay = $request->filled('day') ? (string) $request->string('day') : null;

        if ($scope === 'own') {
            if ($sessionTeacherId === null) {
                return response()->json([
                    'status' => 'success',
                    'scope' => $scope,
                    'teacher_id' => null,
                    'schedules' => [],
                    'filters' => ['groups' => [], 'teachers' => []],
                ]);
            }
            $this->scopeToTeacher($query, $sessionTeacherId);
        } elseif ($scope === 'group') {
            if ($filterTeacherId === null && $filterGroupId === null && $filterDay === null) {
                return response()->json([
                    'status' => 'success',
                    'scope' => $scope,
                    'teacher_id' => $sessionTeacherId,
                    'schedules' => [],
                    'filters' => $this->filterCatalog(),
                ]);
            }
            if ($filterTeacherId !== null) {
                $this->scopeToTeacher($query, $filterTeacherId);
            }
            if ($filterGroupId !== null) {
                $query->whereHas('schoolClass', fn ($sc) => $sc->where('class_group_id', $filterGroupId));
            }
            if ($filterDay !== null) {
                $query->where('day', $filterDay);
            }
        } elseif ($scope === 'all') {
            if ($filterTeacherId !== null) {
                $this->scopeToTeacher($query, $filterTeacherId);
            }
            if ($filterGroupId !== null) {
                $query->whereHas('schoolClass', fn ($sc) => $sc->where('class_group_id', $filterGroupId));
            }
            if ($filterDay !== null) {
                $query->where('day', $filterDay);
            }
        } else {
            return response()->json([
                'status' => 'success',
                'scope' => $scope,
                'teacher_id' => $sessionTeacherId,
                'schedules' => [],
                'filters' => ['groups' => [], 'teachers' => []],
            ]);
        }

        $schedules = $query->get()->map(function (Schedule $schedule) {
            $subject = $schedule->schoolClass?->subject?->name;
            if ($schedule->schedule_type === 'workshop' && $schedule->workshop?->name) {
                $subject = $schedule->workshop->name;
            }

            return [
                'id' => $schedule->id,
                'day' => $schedule->day,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'subject' => $subject,
                'group' => $schedule->schoolClass?->classGroup?->name,
                'grade_level' => $schedule->schoolClass?->classGroup?->gradeLevel?->name,
                'class_group_id' => $schedule->schoolClass?->classGroup?->id,
                'schedule_type' => $schedule->schedule_type,
                'workshop_id' => $schedule->workshop_id,
                'workshop_name' => $schedule->workshop?->name,
                'teacher_id' => $schedule->teacher_id ?? $schedule->schoolClass?->teacher_id,
                'classroom' => $schedule->classroom?->name,
            ];
        });

        return response()->json([
            'status' => 'success',
            'scope' => $scope,
            'teacher_id' => $sessionTeacherId,
            'schedules' => $schedules,
            'filters' => in_array($scope, ['group', 'all'], true) ? $this->filterCatalog() : ['groups' => [], 'teachers' => []],
        ]);
    }

    private function scopeToTeacher($query, int $teacherId): void
    {
        $query->where(function ($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId)
                ->orWhereHas('schoolClass', fn ($sc) => $sc->where('teacher_id', $teacherId));
        });
    }

    /**
     * @return array{groups: list<array<string, mixed>>, teachers: list<array<string, mixed>>}
     */
    private function filterCatalog(): array
    {
        $groups = ClassGroup::query()
            ->with('gradeLevel:id,name')
            ->orderBy('grade_level_id')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'grade_level' => $group->gradeLevel?->name,
            ])
            ->values()
            ->all();

        $teachers = Teacher::query()
            ->with('profile:id,first_name,last_name')
            ->where('status', TeacherStatus::ACTIVE)
            ->orderBy('id')
            ->get()
            ->map(fn (Teacher $teacher) => [
                'id' => $teacher->id,
                'name' => trim(($teacher->profile?->first_name ?? '').' '.($teacher->profile?->last_name ?? '')),
            ])
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'teachers' => $teachers,
        ];
    }
}
