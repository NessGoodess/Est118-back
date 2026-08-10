<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton row: school-wide general attendance schedule.
 */
class AttendanceSetting extends Model
{
    protected $fillable = [
        'timezone',
        'entry_time',
        'tolerance_minutes',
        'exit_earliest',
        'entry_window_closes_at',
        'updated_by',
    ];

    protected $casts = [
        'tolerance_minutes' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        $row = static::query()->first();
        if ($row) {
            return $row;
        }

        return static::query()->create([
            'timezone' => (string) config('attendance.timezone', 'America/Mexico_City'),
            'entry_time' => (string) config('attendance.entry_time', '07:00'),
            'tolerance_minutes' => (int) config('attendance.tolerance_minutes', 10),
            'exit_earliest' => (string) config('attendance.exit_earliest', '13:30'),
            'entry_window_closes_at' => (string) config('attendance.entry_window_closes_at', '12:00'),
        ]);
    }
}
