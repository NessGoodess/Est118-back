<?php

namespace App\Services;

use App\Enums\PreEnrollmentStatus;
use App\Models\PreEnrollment;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PreEnrollmentProcessService
{
    /**
     * Allowed status transitions for PATCH /process (not including convert → approved).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'pending' => ['pending', 'in_review', 'rejected'],
        'in_review' => ['in_review', 'rejected'],
        'rejected' => ['rejected', 'in_review'],
        'approved' => ['approved'],
    ];

    /**
     * @param  array{
     *   expected_updated_at?: string|null,
     *   notes?: string|null,
     *   documents_status?: string|null,
     *   payment_status?: string|null,
     *   admission_exam_score?: float|string|null,
     *   reviewed_by?: int|null,
     * }  $options
     */
    public function startInitialReview(PreEnrollment $preEnrollment, array $options = []): PreEnrollment
    {
        $this->assertNotConverted($preEnrollment);
        $this->assertExpectedUpdatedAt($preEnrollment, $options['expected_updated_at'] ?? null);

        if ($preEnrollment->status !== PreEnrollmentStatus::PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Solo se puede iniciar revisión desde «Solicitud recibida» (pending).'],
            ]);
        }

        $preEnrollment->update([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'reviewed_by' => $options['reviewed_by'] ?? null,
            'reviewed_at' => now(),
            'review_notes' => $options['notes'] ?? $preEnrollment->review_notes,
            'documents_status' => $options['documents_status'] ?? $preEnrollment->documents_status,
            'payment_status' => $options['payment_status'] ?? $preEnrollment->payment_status,
            'admission_exam_score' => array_key_exists('admission_exam_score', $options)
                ? $options['admission_exam_score']
                : $preEnrollment->admission_exam_score,
        ]);

        return $preEnrollment->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProcess(PreEnrollment $preEnrollment, array $data, ?int $actorId = null): PreEnrollment
    {
        $this->assertNotConverted($preEnrollment);
        $this->assertExpectedUpdatedAt($preEnrollment, $data['expected_updated_at'] ?? null);
        unset($data['expected_updated_at']);

        $current = $preEnrollment->status instanceof PreEnrollmentStatus
            ? $preEnrollment->status->value
            : (string) $preEnrollment->status;

        $nextStatus = array_key_exists('status', $data)
            ? (string) $data['status']
            : $current;

        if ($nextStatus === PreEnrollmentStatus::APPROVED->value && $current !== PreEnrollmentStatus::APPROVED->value) {
            throw ValidationException::withMessages([
                'status' => ['El estado «Inscrito» solo se asigna al convertir la solicitud en alumno.'],
            ]);
        }

        $allowed = self::ALLOWED[$current] ?? [];
        if (! in_array($nextStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Transición no permitida: {$current} → {$nextStatus}."],
            ]);
        }

        if (
            $nextStatus === PreEnrollmentStatus::IN_REVIEW->value
            && $current === PreEnrollmentStatus::PENDING->value
            && $preEnrollment->reviewed_at === null
        ) {
            $data['reviewed_by'] = $actorId;
            $data['reviewed_at'] = now();
        }

        if (array_key_exists('review_notes', $data)) {
            $data['review_notes'] = $data['review_notes'] !== null && $data['review_notes'] !== ''
                ? (string) $data['review_notes']
                : null;
        }

        $preEnrollment->update($data);

        return $preEnrollment->fresh();
    }

    private function assertNotConverted(PreEnrollment $preEnrollment): void
    {
        if ($preEnrollment->converted_student_id) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Esta solicitud ya fue inscrita y no se puede modificar el proceso.',
            ], 422));
        }
    }

    private function assertExpectedUpdatedAt(PreEnrollment $preEnrollment, mixed $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        try {
            $expectedAt = Carbon::parse((string) $expected);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'expected_updated_at' => ['Formato de fecha inválido.'],
            ]);
        }

        $actual = $preEnrollment->updated_at;
        if (! $actual) {
            return;
        }

        if ($actual->utc()->format('Y-m-d H:i:s') !== $expectedAt->utc()->format('Y-m-d H:i:s')) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'error_code' => 'stale_version',
                'message' => 'La solicitud fue modificada por otro usuario. Recarga e intenta de nuevo.',
                'updated_at' => $actual->toIso8601String(),
            ], 409));
        }
    }
}
