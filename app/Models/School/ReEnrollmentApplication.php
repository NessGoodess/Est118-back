<?php

namespace App\Models\School;

use App\Enums\PassedCycleSource;
use App\Enums\ReEnrollmentValidationStatus;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReEnrollmentApplication extends Model
{
    protected $fillable = [
        're_enrollment_period_id',
        'enrollment_id',
        'student_id',
        'status',
        'passed_cycle',
        'passed_cycle_source',
        'documents_complete',
        'guardian_updated',
        'phone_updated',
        'address_updated',
        'photo_updated',
        'no_debts',
        'comments',
        'target_class_group_id',
    ];

    protected $casts = [
        'status' => ReEnrollmentValidationStatus::class,
        'passed_cycle' => 'boolean',
        'passed_cycle_source' => PassedCycleSource::class,
        'documents_complete' => 'boolean',
        'guardian_updated' => 'boolean',
        'phone_updated' => 'boolean',
        'address_updated' => 'boolean',
        'photo_updated' => 'boolean',
        'no_debts' => 'boolean',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReEnrollmentPeriod::class, 're_enrollment_period_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function targetClassGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'target_class_group_id');
    }

    public function isChecklistComplete(): bool
    {
        return collect([
            $this->passed_cycle,
            $this->documents_complete,
            $this->guardian_updated,
            $this->phone_updated,
            $this->address_updated,
            $this->photo_updated,
            $this->no_debts,
        ])->every(fn ($value) => $value === true);
    }
}
