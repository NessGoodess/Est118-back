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
        'description',
        'is_active',
    ];

    /**
     * Get all of the classGroup for the AcademicYear
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function classGroup(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }
    /**
     *
     */
    public static function getAcademicYearId($date)
    {
        $date = Carbon::parse($date);
        $year = $date->year;
        $month = $date->month;

        if ($month >= 8) {
            $year_start = $year;
            $year_end = $year + 1;
        } else {
            $year_start = $year - 1;
            $year_end = $year;
        }

        return AcademicYear::where('year_start', $year_start)
            ->where('year_end', $year_end)
            ->value('id');
    }
}
