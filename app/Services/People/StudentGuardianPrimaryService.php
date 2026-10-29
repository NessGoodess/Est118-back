<?php

namespace App\Services\People;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credential holders are primary tutors only. Every student with guardians
 * must have exactly one primary on guardian_student.
 */
class StudentGuardianPrimaryService
{
    /**
     * Fix students with zero or multiple primaries.
     *
     * @return array{
     *   missing: int,
     *   multiple: int,
     *   ok: int,
     *   changes: list<array{student_id: int, name: string, action: 'missing'|'multiple', primary_guardian_id: int}>
     * }
     */
    public function backfillAll(bool $dryRun = false): array
    {
        $stats = ['missing' => 0, 'multiple' => 0, 'ok' => 0, 'changes' => []];
        $studentIds = DB::table('guardian_student')->distinct()->orderBy('student_id')->pluck('student_id');
        $names = $this->studentNames($studentIds);

        foreach ($studentIds as $studentId) {
            $studentId = (int) $studentId;
            $inspected = $this->inspect($studentId);

            if (! $dryRun && $inspected['status'] !== 'ok') {
                $this->ensureExactlyOne($studentId);
            }

            $stats[$inspected['status']]++;

            if ($inspected['status'] === 'ok') {
                continue;
            }

            $stats['changes'][] = [
                'student_id' => $studentId,
                'name' => $names[$studentId] ?? "Alumno #{$studentId}",
                'action' => $inspected['status'],
                'primary_guardian_id' => $inspected['primary_guardian_id'],
            ];
        }

        return $stats;
    }

    /**
     * @return array{status: 'missing'|'multiple'|'ok', primary_guardian_id: int}
     */
    public function inspect(int $studentId): array
    {
        $rows = $this->rowsFor($studentId);
        if ($rows->isEmpty()) {
            return ['status' => 'ok', 'primary_guardian_id' => 0];
        }

        $primaries = $rows->filter(fn ($row) => (bool) $row->is_primary)->values();
        if ($primaries->count() === 1) {
            return [
                'status' => 'ok',
                'primary_guardian_id' => (int) $primaries->first()->guardian_id,
            ];
        }

        if ($primaries->isEmpty()) {
            return [
                'status' => 'missing',
                'primary_guardian_id' => (int) $rows->first()->guardian_id,
            ];
        }

        return [
            'status' => 'multiple',
            'primary_guardian_id' => (int) $primaries->first()->guardian_id,
        ];
    }

    /**
     * @return 'missing'|'multiple'|'ok'
     *   missing  — had guardians but no primary (promoted one)
     *   multiple — had >1 primary (kept one)
     *   ok       — already exactly one, or no guardians
     */
    public function ensureExactlyOne(int $studentId): string
    {
        $inspected = $this->inspect($studentId);
        if ($inspected['status'] === 'ok') {
            return 'ok';
        }

        $rows = $this->rowsFor($studentId);
        $primaries = $rows->filter(fn ($row) => (bool) $row->is_primary)->values();

        if ($primaries->isEmpty()) {
            $pick = $rows->first();
            DB::table('guardian_student')->where('id', $pick->id)->update([
                'is_primary' => true,
                'is_secondary' => false,
            ]);

            return 'missing';
        }

        $keepId = (int) $primaries->first()->id;
        DB::table('guardian_student')
            ->where('student_id', $studentId)
            ->where('id', '!=', $keepId)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        DB::table('guardian_student')->where('id', $keepId)->update([
            'is_primary' => true,
            'is_secondary' => false,
        ]);

        return 'multiple';
    }

    /**
     * Block clearing the only primary without designating another.
     */
    public function assertCanClearPrimary(int $studentId, int $guardianId): void
    {
        $otherPrimary = DB::table('guardian_student')
            ->where('student_id', $studentId)
            ->where('guardian_id', '!=', $guardianId)
            ->where('is_primary', true)
            ->exists();

        if ($otherPrimary) {
            return;
        }

        throw ValidationException::withMessages([
            'is_primary' => ['Debes marcar otro tutor como principal antes de quitar este.'],
        ]);
    }

    /**
     * @return Collection<int, object{id: int, guardian_id: int, is_primary: mixed, is_secondary: mixed}>
     */
    private function rowsFor(int $studentId): Collection
    {
        return DB::table('guardian_student')
            ->where('student_id', $studentId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'guardian_id', 'is_primary', 'is_secondary']);
    }

    /**
     * @param  Collection<int, int|string>  $studentIds
     * @return array<int, string>
     */
    private function studentNames(Collection $studentIds): array
    {
        if ($studentIds->isEmpty()) {
            return [];
        }

        return DB::table('students')
            ->join('profiles', 'profiles.id', '=', 'students.profile_id')
            ->whereIn('students.id', $studentIds->all())
            ->select([
                'students.id',
                'profiles.first_name',
                'profiles.last_name',
                'profiles.second_last_name',
            ])
            ->get()
            ->mapWithKeys(function ($row) {
                $name = trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->last_name,
                    $row->second_last_name,
                ])));

                return [(int) $row->id => $name !== '' ? $name : "Alumno #{$row->id}"];
            })
            ->all();
    }
}
