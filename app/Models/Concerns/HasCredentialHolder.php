<?php

namespace App\Models\Concerns;

use App\Enums\CredentialHolderKind;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared by CredentialPrint and PrintJob: student_id for student cards,
 * profile_id + kind for every holder.
 */
trait HasCredentialHolder
{
    public static function bootHasCredentialHolder(): void
    {
        static::creating(function (self $model): void {
            if ($model->kind === null) {
                $model->kind = CredentialHolderKind::Students;
            }
            if ($model->profile_id === null && $model->student_id !== null) {
                $model->profile_id = Student::query()->whereKey($model->student_id)->value('profile_id');
            }
        });
    }

    public function initializeHasCredentialHolder(): void
    {
        $this->mergeFillable(['profile_id', 'kind']);
        $this->mergeCasts(['kind' => CredentialHolderKind::class]);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function holderProfile(): ?Profile
    {
        return $this->student?->profile ?? $this->profile;
    }

    public function holderName(): ?string
    {
        return $this->holderProfile()?->fullName();
    }

    public function kindValue(): string
    {
        return ($this->kind ?? CredentialHolderKind::Students)->value;
    }
}
