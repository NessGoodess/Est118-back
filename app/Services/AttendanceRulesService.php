<?php

namespace App\Services;

use App\Models\AttendanceSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Shared attendance schedule rules (entry, late cutoff, exit, timezone).
 * Prefers attendance_settings (DB); falls back to config/attendance.php.
 */
class AttendanceRulesService
{
    public const CACHE_KEY = 'attendance.settings.current';

    public function timezone(): string
    {
        return (string) ($this->settings()->timezone
            ?: config('attendance.timezone', 'America/Mexico_City'));
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    public function entryTime(): string
    {
        return $this->normalizeTime(
            (string) ($this->settings()->entry_time
                ?: config('attendance.entry_time', '07:00'))
        );
    }

    public function toleranceMinutes(): int
    {
        return max(
            0,
            (int) ($this->settings()->tolerance_minutes
                ?? config('attendance.tolerance_minutes', 10))
        );
    }

    public function exitEarliest(): string
    {
        return $this->normalizeTime(
            (string) ($this->settings()->exit_earliest
                ?: config('attendance.exit_earliest', '13:30'))
        );
    }

    public function entryWindowClosesAt(): string
    {
        return $this->normalizeTime(
            (string) ($this->settings()->entry_window_closes_at
                ?: config('attendance.entry_window_closes_at', '12:00'))
        );
    }

    /**
     * First minute that counts as late (entry_time + tolerance).
     */
    public function lateAfter(): string
    {
        return Carbon::createFromFormat('H:i', $this->entryTime(), $this->timezone())
            ->addMinutes($this->toleranceMinutes())
            ->format('H:i');
    }

    public function isLateEntry(\DateTimeInterface $time): bool
    {
        $compare = Carbon::instance(\DateTimeImmutable::createFromInterface($time))
            ->timezone($this->timezone())
            ->format('H:i');

        return $compare > $this->lateAfter();
    }

    public function canRegisterExit(\DateTimeInterface $time): bool
    {
        $compare = Carbon::instance(\DateTimeImmutable::createFromInterface($time))
            ->timezone($this->timezone())
            ->format('H:i');

        return $compare >= $this->exitEarliest();
    }

    /**
     * After this time on a school day, missing students become virtual absents.
     */
    public function isAfterEntryWindowClose(\DateTimeInterface $time): bool
    {
        $compare = Carbon::instance(\DateTimeImmutable::createFromInterface($time))
            ->timezone($this->timezone())
            ->format('H:i');

        return $compare >= $this->entryWindowClosesAt();
    }

    /**
     * @return array{timezone: string, entry_time: string, tolerance_minutes: int, late_after: string, exit_from: string, entry_window_closes_at: string}
     */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone(),
            'entry_time' => $this->entryTime(),
            'tolerance_minutes' => $this->toleranceMinutes(),
            'late_after' => $this->lateAfter(),
            'exit_from' => $this->exitEarliest(),
            'entry_window_closes_at' => $this->entryWindowClosesAt(),
        ];
    }

    public function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected function settings(): AttendanceSetting
    {
        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['id'])) {
            $model = new AttendanceSetting();
            $model->forceFill($cached);
            $model->exists = true;

            return $model;
        }

        $row = AttendanceSetting::current();
        Cache::forever(self::CACHE_KEY, $row->attributesToArray());

        return $row;
    }

    protected function normalizeTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return substr($value, 0, 5);
        }

        return $value;
    }
}
