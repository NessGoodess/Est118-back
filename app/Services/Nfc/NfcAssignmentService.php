<?php

namespace App\Services\Nfc;

use App\Enums\EnrollmentStatus;
use App\Events\NfcAssignmentUpdated;
use App\Models\NfcAssignments;
use App\Models\Student;
use App\Models\StudentCredentialTracking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class NfcAssignmentService
{
    /** Official CURP shape: 4 letters, 6 digits, sex, 5 letters, check, digit. */
    public const CURP_PATTERN = '/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/';

    /**
     * @return array{created: bool, job: NfcAssignments}
     */
    public function enqueueResult(Student $student, string $action, User $user): array
    {
        $enrollment = $student->enrollments()
            ->where('status', EnrollmentStatus::Active)
            ->latest('id')
            ->first();

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'student_id' => 'El alumno no tiene inscripción activa.',
            ]);
        }

        $credentialId = $this->ensureCredentialId($student);

        $result = DB::transaction(function () use ($student, $action, $user, $enrollment, $credentialId) {
            $open = NfcAssignments::query()
                ->whereIn('status', NfcAssignments::OPEN_STATUSES)
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if ($open) {
                return ['created' => false, 'job' => $open];
            }

            $job = NfcAssignments::query()->create([
                'student_id' => $student->id,
                'academic_year_id' => $enrollment->academic_year_id,
                'action' => $action,
                'status' => NfcAssignments::STATUS_PENDING,
                'expected_credential_id' => $credentialId,
                'created_by' => $user->id,
                'status_message' => 'En espera del agente y del lector.',
            ]);

            return ['created' => true, 'job' => $job];
        });

        if ($result['created']) {
            $this->broadcast($result['job']);
        }

        return $result;
    }

    public function claimNext(string $agentId): ?NfcAssignments
    {
        $claimed = false;
        $job = DB::transaction(function () use ($agentId, &$claimed) {
            $mine = NfcAssignments::query()
                ->where('claimed_by', $agentId)
                ->whereIn('status', NfcAssignments::OPEN_STATUSES)
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if ($mine) {
                return $mine;
            }

            $job = NfcAssignments::query()
                ->where('status', NfcAssignments::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return null;
            }

            $job->update([
                'status' => NfcAssignments::STATUS_WAITING_CARD,
                'claimed_by' => $agentId,
                'claimed_at' => now(),
                'device_id' => $agentId,
                'started_at' => $job->started_at ?? now(),
                'status_message' => 'Acerca la credencial al lector y no la retires.',
            ]);
            $claimed = true;

            return $job->fresh();
        });

        if ($claimed && $job) {
            $this->broadcast($job);
        }

        return $job;
    }

    public function progress(NfcAssignments $job, string $agentId, string $status, ?string $nfcUid, ?string $message, ?string $readerName): NfcAssignments
    {
        $this->assertAgent($job, $agentId);

        if (! $job->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'El trabajo ya terminó.',
            ])->status(409);
        }

        if (! in_array($status, NfcAssignments::PROGRESS_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Estado de progreso no válido.',
            ]);
        }

        $job->update([
            'status' => $status,
            'nfc_uid' => $nfcUid ?: $job->nfc_uid,
            'device_id' => $readerName ?: $job->device_id,
            'status_message' => $message ?: $job->status_message,
        ]);

        return $this->broadcast($job->fresh());
    }

    public function complete(NfcAssignments $job, string $agentId, ?string $nfcUid, ?string $readBack): NfcAssignments
    {
        $this->assertAgent($job, $agentId);

        if (! $job->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'El trabajo ya terminó.',
            ])->status(409);
        }

        $expected = (string) $job->expected_credential_id;
        $actual = $this->normalizeReadBack($readBack);

        if (strlen($expected) !== strlen($actual) || ! hash_equals($expected, $actual)) {
            return $this->markFailed(
                $job,
                NfcAssignments::FAILURE_VERIFY_MISMATCH,
                'La relectura no coincide con la CURP del alumno.',
                $nfcUid,
                $actual !== '' ? $actual : $readBack
            );
        }

        $job->update([
            'status' => NfcAssignments::STATUS_COMPLETED,
            'failure_code' => null,
            'nfc_uid' => $nfcUid ?: $job->nfc_uid,
            'read_back' => $actual,
            'status_message' => 'CURP verificada en la tarjeta.',
            'completed_at' => now(),
        ]);

        $this->setNfcReady($job, true);

        return $this->broadcast($job->fresh());
    }

    public function fail(
        NfcAssignments $job,
        string $agentId,
        string $failureCode,
        string $message,
        ?string $nfcUid,
        ?string $readBack
    ): NfcAssignments {
        $this->assertAgent($job, $agentId);

        if (! $job->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'El trabajo ya terminó.',
            ])->status(409);
        }

        if (! in_array($failureCode, NfcAssignments::FAILURE_CODES, true)) {
            throw ValidationException::withMessages([
                'failure_code' => 'Código de fallo no válido.',
            ]);
        }

        return $this->markFailed($job, $failureCode, $message, $nfcUid, $readBack);
    }

    public function cancel(NfcAssignments $job): NfcAssignments
    {
        if (! $job->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'El trabajo ya terminó.',
            ])->status(409);
        }

        return $this->markFailed(
            $job,
            NfcAssignments::FAILURE_CANCELLED,
            'Cancelado por el operador.',
            $job->nfc_uid,
            $job->read_back
        );
    }

    public function active(): ?NfcAssignments
    {
        return NfcAssignments::query()
            ->with('student.profile')
            ->whereIn('status', NfcAssignments::OPEN_STATUSES)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<string, array<string, mixed>>
     */
    public function latestByStudents(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $jobs = NfcAssignments::query()
            ->with('student.profile')
            ->whereIn('student_id', $studentIds)
            ->orderByDesc('id')
            ->get()
            ->unique('student_id');

        $map = [];
        foreach ($jobs as $job) {
            $map[(string) $job->student_id] = $this->payload($job);
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(NfcAssignments $job): array
    {
        $job->loadMissing('student.profile');
        $profile = $job->student?->profile;

        return [
            'job_id' => $job->uuid,
            'student_id' => $job->student_id,
            'student_name' => trim(((string) ($profile->first_name ?? '')).' '.((string) ($profile->last_name ?? ''))),
            'action' => $job->action,
            'status' => $job->status,
            'status_message' => $job->status_message,
            'failure_code' => $job->failure_code,
            'nfc_uid' => $job->nfc_uid,
            'credential_id' => $job->expected_credential_id,
            'read_back' => $job->read_back,
            'occupant' => $job->assignment_data['occupant'] ?? null,
            'claimed_by' => $job->claimed_by,
            'academic_year_id' => $job->academic_year_id,
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agentPayload(NfcAssignments $job): array
    {
        $payload = $this->payload($job);

        return [
            'job_id' => $payload['job_id'],
            'action' => $payload['action'],
            'credential_id' => $payload['credential_id'],
            'status' => $payload['status'],
            'failure_code' => $payload['failure_code'],
            'status_message' => $payload['status_message'],
            'student' => [
                'id' => $payload['student_id'],
                'name' => $payload['student_name'] !== '' ? $payload['student_name'] : 'Alumno',
            ],
        ];
    }

    private function markFailed(
        NfcAssignments $job,
        string $failureCode,
        string $message,
        ?string $nfcUid,
        ?string $readBack
    ): NfcAssignments {
        $normalized = $readBack !== null && $readBack !== '' ? $this->normalizeReadBack($readBack) : null;
        $data = $job->assignment_data ?? [];
        $resolved = $normalized !== null ? $this->resolveOccupant($failureCode, $normalized) : null;
        if ($resolved !== null) {
            $message = $resolved['message'];
            $data['occupant'] = $resolved['occupant'];
        }

        $job->update([
            'status' => NfcAssignments::STATUS_ERROR,
            'failure_code' => $failureCode,
            'status_message' => $message,
            'nfc_uid' => $nfcUid ?: $job->nfc_uid,
            'read_back' => $normalized ?? $job->read_back,
            'assignment_data' => $data,
            'completed_at' => now(),
        ]);

        $this->setNfcReady($job, false);

        return $this->broadcast($job->fresh());
    }

    /**
     * Realtime is a shortcut for the panel; polling still covers it if Reverb is down.
     */
    private function broadcast(NfcAssignments $job): NfcAssignments
    {
        try {
            event(new NfcAssignmentUpdated($this->payload($job)));
        } catch (Throwable $e) {
            Log::warning('NFC assignment broadcast failed', [
                'job' => $job->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $job;
    }

    private function setNfcReady(NfcAssignments $job, bool $ready): void
    {
        if (! $job->academic_year_id) {
            return;
        }

        StudentCredentialTracking::query()->updateOrCreate(
            [
                'student_id' => $job->student_id,
                'academic_year_id' => $job->academic_year_id,
            ],
            ['nfc_ready' => $ready]
        );
    }

    private function assertAgent(NfcAssignments $job, string $agentId): void
    {
        if ($job->claimed_by && $job->claimed_by !== $agentId) {
            throw ValidationException::withMessages([
                'agent_id' => 'El trabajo lo tiene otro agente.',
            ])->status(409);
        }
    }

    /**
     * The text written on the card is the student's CURP. A previous folio is replaced.
     */
    private function ensureCredentialId(Student $student): string
    {
        $student->loadMissing('profile');
        $curp = strtoupper(trim((string) ($student->profile?->national_id ?? '')));

        if (! preg_match(self::CURP_PATTERN, $curp)) {
            throw ValidationException::withMessages([
                'student_id' => 'El alumno no tiene una CURP válida.',
            ]);
        }

        if ((string) $student->credential_id === $curp) {
            return $curp;
        }

        $taken = Student::query()
            ->where('credential_id', $curp)
            ->where('id', '!=', $student->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'student_id' => 'Otro alumno ya tiene esta CURP como credencial.',
            ]);
        }

        $student->credential_id = $curp;
        $student->save();

        return $curp;
    }

    /**
     * A CURP-shaped read-back names its owner. Anything else keeps the agent's message.
     *
     * @return array{message: string, occupant: array{student_id: int, name: string, curp: string}|null}|null
     */
    private function resolveOccupant(string $failureCode, string $readBack): ?array
    {
        if (! in_array($failureCode, [
            NfcAssignments::FAILURE_TAG_OCCUPIED,
            NfcAssignments::FAILURE_VERIFY_MISMATCH,
        ], true)) {
            return null;
        }

        $curp = strtoupper($readBack);
        if (! preg_match(self::CURP_PATTERN, $curp)) {
            return null;
        }

        $student = Student::query()
            ->with('profile')
            ->whereHas('profile', fn ($q) => $q->whereRaw('UPPER(national_id) = ?', [$curp]))
            ->first();

        if (! $student) {
            return [
                'message' => "La tarjeta tiene la CURP {$curp}, que no es de ningún alumno registrado.",
                'occupant' => null,
            ];
        }

        $profile = $student->profile;
        $name = trim(((string) ($profile->first_name ?? '')).' '.((string) ($profile->last_name ?? '')));
        if ($name === '') {
            $name = 'Alumno';
        }

        return [
            'message' => "La tarjeta ya pertenece a {$name} ({$curp}).",
            'occupant' => [
                'student_id' => $student->id,
                'name' => $name,
                'curp' => $curp,
            ],
        ];
    }

    private function normalizeReadBack(?string $readBack): string
    {
        return strtoupper(trim(rtrim((string) $readBack, "\0")));
    }
}
