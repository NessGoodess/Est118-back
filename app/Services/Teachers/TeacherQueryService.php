<?php

namespace App\Services\Teachers;

use App\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;

class TeacherQueryService
{
    /**
     * @return Collection<int, Teacher>
     */
    public function list(?string $status = null): Collection
    {
        $query = Teacher::query()
            ->with('profile')
            ->withCount('schoolClasses')
            ->join('profiles', 'profiles.id', '=', 'teachers.profile_id')
            ->orderBy('profiles.last_name')
            ->orderBy('profiles.first_name')
            ->select('teachers.*');

        if ($status !== null && $status !== '') {
            $query->where('teachers.status', $status);
        }

        return $query->get();
    }

    public function detail(Teacher $teacher): Teacher
    {
        return $teacher->load([
            'profile.address',
            'schoolClasses.subject',
            'schoolClasses.classGroup.gradeLevel',
            'schoolClasses.classGroup.academicYear',
        ]);
    }
}
