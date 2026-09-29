<?php

namespace App\Services\Print;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CredentialPrintBackfill
{
    public function run(): void
    {
        if (! Schema::hasTable('print_jobs') || ! Schema::hasTable('credential_prints')) {
            return;
        }

        $jobs = DB::table('print_jobs')->orderBy('student_id')->orderBy('id')->get();
        if ($jobs->isEmpty()) {
            return;
        }

        $designsById = DB::table('card_designs')->get()->keyBy('id');
        $designsByUuid = DB::table('card_designs')->get()->keyBy('uuid');
        $migratedPendingBatch = (string) Str::uuid();
        $now = now();

        /** @var array<int, array<int, array<string, mixed>>> $openByStudent */
        $openByStudent = [];

        foreach ($jobs as $job) {
            if ($job->credential_print_id) {
                continue;
            }

            $payload = $this->payload($job->payload_json ?? null);
            $designId = $job->card_design_id ? (int) $job->card_design_id : null;
            if (! $designId && ! empty($payload['design_key'])) {
                $designId = isset($designsByUuid[$payload['design_key']])
                    ? (int) $designsByUuid[$payload['design_key']]->id
                    : null;
            }
            $design = $designId && isset($designsById[$designId]) ? $designsById[$designId] : null;
            $facesMode = $this->facesMode($payload, $design);
            $side = $job->side_mode === 'back' ? 'back' : 'front';
            $sideStatus = $this->statusFromJob((string) $job->status);

            if ($side === 'back') {
                $cardId = $this->attachBack(
                    $openByStudent,
                    (int) $job->student_id,
                    $designId,
                    $sideStatus,
                    $job->completed_at,
                    $now
                );
                DB::table('print_jobs')->where('id', $job->id)->update([
                    'credential_print_id' => $cardId,
                ]);
                continue;
            }

            $backStatus = $facesMode === 'double' ? 'pending' : 'not_applicable';
            $frontPrintedAt = $sideStatus === 'printed' ? ($job->completed_at ?? $now) : null;
            $completedAt = null;
            $reason = 'migrated';
            $batchUuid = (string) Str::uuid();

            if ($facesMode === 'single' && in_array($sideStatus, ['printed', 'not_applicable'], true)) {
                $completedAt = $frontPrintedAt ?? $now;
            }

            if ($facesMode === 'double' && $backStatus === 'pending') {
                $batchUuid = $migratedPendingBatch;
            }

            $cardId = DB::table('credential_prints')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'student_id' => $job->student_id,
                'academic_year_id' => $job->academic_year_id,
                'card_design_id' => $designId,
                'faces_mode' => $facesMode,
                'front_status' => $sideStatus,
                'back_status' => $backStatus,
                'strategy' => 'fronts_then_backs',
                'batch_uuid' => $batchUuid,
                'reason' => $reason,
                'created_by' => $job->created_by,
                'front_printed_at' => $frontPrintedAt,
                'completed_at' => $completedAt,
                'created_at' => $job->created_at ?? $now,
                'updated_at' => $now,
            ]);

            DB::table('print_jobs')->where('id', $job->id)->update([
                'credential_print_id' => $cardId,
            ]);

            if ($facesMode === 'double' && $backStatus === 'pending') {
                $openByStudent[(int) $job->student_id][$cardId] = [
                    'id' => $cardId,
                    'card_design_id' => $designId,
                ];
            }
        }
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $openByStudent
     */
    private function attachBack(
        array &$openByStudent,
        int $studentId,
        ?int $designId,
        string $sideStatus,
        mixed $completedAt,
        mixed $now
    ): int {
        $open = $openByStudent[$studentId] ?? [];
        $matchId = null;
        foreach (array_reverse($open, true) as $cardId => $meta) {
            if ($designId === null || $meta['card_design_id'] === $designId) {
                $matchId = (int) $cardId;
                break;
            }
        }

        if ($matchId === null) {
            $matchId = DB::table('credential_prints')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'student_id' => $studentId,
                'academic_year_id' => null,
                'card_design_id' => $designId,
                'faces_mode' => 'double',
                'front_status' => 'printed',
                'back_status' => $sideStatus,
                'strategy' => 'fronts_then_backs',
                'batch_uuid' => (string) Str::uuid(),
                'reason' => 'migrated',
                'front_printed_at' => $now,
                'back_printed_at' => $sideStatus === 'printed' ? ($completedAt ?? $now) : null,
                'completed_at' => $sideStatus === 'printed' ? ($completedAt ?? $now) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $matchId;
        }

        $update = [
            'back_status' => $sideStatus,
            'updated_at' => $now,
        ];
        if ($sideStatus === 'printed') {
            $update['back_printed_at'] = $completedAt ?? $now;
            $update['completed_at'] = $completedAt ?? $now;
        }

        DB::table('credential_prints')->where('id', $matchId)->update($update);
        unset($openByStudent[$studentId][$matchId]);

        return $matchId;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function facesMode(array $payload, mixed $design): string
    {
        $fromPayload = $payload['faces_mode'] ?? null;
        if ($fromPayload === 'double') {
            return 'double';
        }
        if ($fromPayload === 'single') {
            return 'single';
        }
        if (is_object($design) && ($design->faces_mode ?? null) === 'double') {
            return 'double';
        }

        return 'single';
    }

    private function statusFromJob(string $status): string
    {
        return match ($status) {
            'completed' => 'printed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}
