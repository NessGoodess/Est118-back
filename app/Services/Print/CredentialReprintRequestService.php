<?php

namespace App\Services\Print;

use App\Enums\CredentialReprintReason;
use App\Enums\CredentialReprintStatus;
use App\Models\CredentialReprintRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CredentialReprintRequestService
{
    /** Filter buckets shown as chips; "closed" groups every final status. */
    public const BUCKETS = ['pending', 'awaiting_payment', 'ready_to_print', 'queued', 'closed'];

    private const RELATIONS = [
        'student.profile:id,first_name,last_name,second_last_name',
        'requester:id,name',
        'reviewer:id,name',
        'payer:id,name',
    ];

    /**
     * @param  array{student_id: int, reason: string, notes?: ?string, requester_relation?: ?string, requester_name?: ?string}  $data
     */
    public function create(array $data, User $user): CredentialReprintRequest
    {
        $reason = CredentialReprintReason::from($data['reason']);
        $student = Student::query()
            ->with('currentEnrollment.classGroup.gradeLevel')
            ->findOrFail((int) $data['student_id']);

        $open = CredentialReprintRequest::query()->open()->where('student_id', $student->id)->first();
        if ($open) {
            throw ValidationException::withMessages([
                'student_id' => ['El alumno ya tiene una solicitud abierta ('.$this->statusLabel($open->status).').'],
            ]);
        }

        $enrollment = $student->currentEnrollment;
        $group = $enrollment?->classGroup;
        $chargeable = $reason->isChargeable();

        try {
            $request = CredentialReprintRequest::query()->create([
                'student_id' => $student->id,
                'academic_year_id' => $enrollment?->academic_year_id,
                'class_group_id' => $group?->id,
                'grade_label' => $group?->gradeLevel?->name,
                'group_label' => $group?->name,
                'reason' => $reason,
                'notes' => $data['notes'] ?? null,
                'requester_relation' => $data['requester_relation'] ?? 'student',
                'requester_name' => $data['requester_name'] ?? null,
                'status' => $chargeable ? CredentialReprintStatus::Pending : CredentialReprintStatus::ReadyToPrint,
                'is_chargeable' => $chargeable,
                'requested_by' => $user->id,
                'reviewed_at' => $chargeable ? null : now(),
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'student_id' => ['El alumno ya tiene una solicitud abierta.'],
            ]);
        }

        return $request->load(self::RELATIONS);
    }

    public function approve(CredentialReprintRequest $request, User $user): CredentialReprintRequest
    {
        $this->assertStatus($request, [CredentialReprintStatus::Pending], 'aprobar');

        $request->update([
            'status' => $request->is_chargeable
                ? CredentialReprintStatus::AwaitingPayment
                : CredentialReprintStatus::ReadyToPrint,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return $request->load(self::RELATIONS);
    }

    public function reject(CredentialReprintRequest $request, User $user, string $reason): CredentialReprintRequest
    {
        $this->assertStatus($request, [
            CredentialReprintStatus::Pending,
            CredentialReprintStatus::AwaitingPayment,
        ], 'rechazar');

        $request->update([
            'status' => CredentialReprintStatus::Rejected,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'reject_reason' => $reason,
        ]);

        return $request->load(self::RELATIONS);
    }

    public function registerPayment(
        CredentialReprintRequest $request,
        User $user,
        ?string $folio,
        ?float $amount
    ): CredentialReprintRequest {
        $this->assertStatus($request, [CredentialReprintStatus::AwaitingPayment], 'registrar el pago de');

        $request->update([
            'status' => CredentialReprintStatus::ReadyToPrint,
            'paid_by' => $user->id,
            'paid_at' => now(),
            'payment_folio' => $folio,
            'payment_amount' => $amount,
        ]);

        return $request->load(self::RELATIONS);
    }

    public function cancel(CredentialReprintRequest $request, User $user): CredentialReprintRequest
    {
        $this->assertStatus($request, [
            CredentialReprintStatus::Pending,
            CredentialReprintStatus::AwaitingPayment,
            CredentialReprintStatus::ReadyToPrint,
        ], 'cancelar');

        $request->update([
            'status' => CredentialReprintStatus::Cancelled,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return $request->load(self::RELATIONS);
    }

    /**
     * @param  array{bucket?: ?string, reason?: ?string, search?: ?string, student_id?: ?int, class_group_id?: ?int, per_page?: ?int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = CredentialReprintRequest::query()->with(self::RELATIONS);
        $this->applyFilters($query, $filters);

        $bucket = $filters['bucket'] ?? null;
        if ($bucket === 'closed') {
            $query->whereNotIn('status', CredentialReprintStatus::open());
        } elseif ($bucket && in_array($bucket, self::BUCKETS, true)) {
            $query->where('status', $bucket);
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        // Open buckets are a work queue (oldest first); closed and unfiltered lists read newest first.
        if ($bucket === 'closed') {
            $query->orderByDesc('updated_at')->orderByDesc('id');
        } elseif ($bucket) {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        return $query->paginate($perPage);
    }

    /**
     * Counts per bucket with the same reason/search filters (chips ignore the bucket itself).
     *
     * @param  array{reason?: ?string, search?: ?string, student_id?: ?int, class_group_id?: ?int}  $filters
     * @return array<string, int>
     */
    public function counts(array $filters = []): array
    {
        $query = CredentialReprintRequest::query();
        $this->applyFilters($query, $filters);

        $byStatus = $query
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = array_fill_keys(self::BUCKETS, 0);
        foreach ($byStatus as $status => $total) {
            $key = in_array($status, array_map(fn ($s) => $s->value, CredentialReprintStatus::open()), true)
                ? $status
                : 'closed';
            $counts[$key] += (int) $total;
        }

        return $counts;
    }

    /**
     * Active students for the request form, with their open request if any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchStudents(string $search, int $limit = 15): array
    {
        $terms = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($terms === []) {
            return [];
        }

        $students = Student::query()
            ->whereHas('currentEnrollment')
            ->whereHas('profile', function (Builder $profile) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $profile->where(fn (Builder $q) => $q
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('second_last_name', 'like', $like));
                }
            })
            ->with([
                'profile:id,first_name,last_name,second_last_name',
                'currentEnrollment.classGroup.gradeLevel',
            ])
            ->limit($limit)
            ->get();

        $open = CredentialReprintRequest::query()
            ->open()
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('status', 'student_id');

        return $students->map(function (Student $student) use ($open) {
            $profile = $student->profile;
            $group = $student->currentEnrollment?->classGroup;
            $status = $open->get($student->id);

            return [
                'id' => $student->id,
                'name' => trim(implode(' ', array_filter([
                    $profile?->first_name,
                    $profile?->last_name,
                    $profile?->second_last_name,
                ]))),
                'grade_label' => $group?->gradeLevel?->name,
                'group_label' => $group?->name,
                'open_request_status' => $status instanceof CredentialReprintStatus ? $status->value : $status,
            ];
        })->values()->all();
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }

        if (! empty($filters['class_group_id'])) {
            $query->where('class_group_id', (int) $filters['class_group_id']);
        }

        if (! empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $query->whereHas('student.profile', function (Builder $profile) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $profile->where(fn (Builder $q) => $q
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('second_last_name', 'like', $like));
                }
            });
        }
    }

    /** @param  array<int, CredentialReprintStatus>  $allowed */
    private function assertStatus(CredentialReprintRequest $request, array $allowed, string $action): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$action} una solicitud en estado «{$this->statusLabel($request->status)}»."],
            ]);
        }
    }

    private function statusLabel(CredentialReprintStatus $status): string
    {
        return match ($status) {
            CredentialReprintStatus::Pending => 'pendiente de aprobación',
            CredentialReprintStatus::AwaitingPayment => 'por pagar',
            CredentialReprintStatus::ReadyToPrint => 'lista para imprimir',
            CredentialReprintStatus::Queued => 'en impresión',
            CredentialReprintStatus::Printed => 'impresa',
            CredentialReprintStatus::Rejected => 'rechazada',
            CredentialReprintStatus::Cancelled => 'cancelada',
        };
    }
}
