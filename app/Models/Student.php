<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Student extends Model
{
    /** @use HasFactory<\Database\Factories\StudentFactory> */
    use HasFactory;
    protected $fillable = [
        'profile_id',
        'credential_id',
    ];

    /**
     * Get the profile associated with the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Get all of the enrollments for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
    /**
     * The guardians that belong to the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student');
    }

    /**
     * The groups that belong to the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(classGroup::class, 'enrollments');
    }

    /**
     * The gradeLevels that belong to the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function gradeLevels(): BelongsToMany
    {
        return $this->belongsToMany(GradeLevel::class, 'enrollments');
    }

    /**
     * Get all of the currentGroup for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough
     */
    public function currentGroup(): HasOneThrough
    {
        return $this->hasOneThrough(
            ClassGroup::class,
            Enrollment::class,
            'student_id', // Foreign key on enrollments table...
            'id', // Foreign key on class_groups table...
            'id', // Local key on students table...
            'class_group_id' // Local key on enrollments table...
        )->where('enrollments.status', 'active');
    }

    /**
     * Get the schedule for the Student's current group
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGroupSchedule()
    {
        $currentEnrollment = $this->enrollments()->where('status', 'active')->first();

        if ($currentEnrollment && $currentEnrollment->classGroup) {
            return Schedule::whereIn(
                'school_class_id',
                $currentEnrollment
                    ->classGroup
                    ->classes
                    ->pluck('id')
            )->get();
        }

        return collect(); // Return an empty collection if no active enrollment or class group is found
    }

    /**
     * Get all of the attendance for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get all of the generalAttendance for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function generalAttendance(): HasMany
    {
        return $this->hasMany(GeneralAttendance::class);
    }

    /**
     * Get all of the incidents for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get all of the permissions for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(StudentPermission::class);
    }

    /**
     * Get attendances in a specific date range
     *
     * @param  string  $startDate
     * @param  string  $endDate
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function attendancesInDateRange($startDate, $endDate)
    {
        return $this->generalAttendance()
            ->whereBetween('date', [$startDate, $endDate])
            ->get();
    }

    /**
     * Get today's attendance record
     *
     * @return \App\Models\Attendance|null
     */
    public function getTodayAttendence()
    {
        return $this->attendance()
            ->whereDate('date', today())
            ->schoolAttendance()
            ->first();
    }

    /**
     * Get all active permissions for the Student
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function activePermissions()
    {
        return $this->permissions()->active()->get();
    }

    /**
     * The workshops that belong to the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function workshops()
    {
        return $this->belongsToMany(Workshop::class, 'workshop_enrollments')
            ->withPivot('academic_year_id');
    }

    /**
     * Get the student's full name from the profile
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return $this->profile->first_name . ' ' . $this->profile->last_name;
    }

    /**
     * Get the current enrollment of the student
     *
     * @return \App\Models\Enrollment|null
     */
    public function getCurrentEnrollmentAttribute()
    {
        return $this->enrollments->where('status', 'active')->first();
    }

    /**
     * The class schedules that belong to the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function classSchedules(): BelongsToMany
    {
        return $this->belongsToMany(
            Schedule::class,
            'class_students' // tabla pivote
        )->withPivot('status')
            ->withTimestamps();
    }

    /**
     * Get all of the class student entries for the Student
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function classStudentEntries(): HasMany
    {
        return $this->hasMany(ClassStudent::class);
    }

    public function currentEnrollment()
    {
        return $this->hasOne(Enrollment::class)
            ->where('status', 'active')
            ->latest();
    }
}
