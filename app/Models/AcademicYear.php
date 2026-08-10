<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    /** @use HasFactory<\Database\Factories\AcademicYearFactory> */
    use HasFactory;

    protected $fillable = [
        'year_start',
        'year_end',
        'starts_on',
        'ends_on',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get all of the classGroup for the AcademicYear
     */
    public function classGroup(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public static function rangesOverlap(
        string $startsOn,
        string $endsOn,
        ?int $ignoreId = null
    ): bool {
        return static::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->exists();
    }

    public static function getAcademicYearId($date)
    {
        $day = Carbon::parse($date)->toDateString();

        $id = static::query()
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->orderByDesc('starts_on')
            ->value('id');

        if ($id !== null) {
            return $id;
        }

        // Fallback for legacy rows without dates (should not happen after migrate).
        $parsed = Carbon::parse($date);
        $year = $parsed->year;
        $month = $parsed->month;

        if ($month >= 8) {
            $yearStart = $year;
            $yearEnd = $year + 1;
        } else {
            $yearStart = $year - 1;
            $yearEnd = $year;
        }

        return static::query()
            ->where('year_start', $yearStart)
            ->where('year_end', $yearEnd)
            ->value('id');
    }
}
