<?php

namespace App\Services\Print;

use App\Events\CredentialPrintUpdated;
use App\Events\PrintAgentUpdated;
use App\Events\PrintJobUpdated;
use App\Models\CredentialPrint;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Realtime is a shortcut for the panel. Polling still covers a missed event
 * if Reverb is down. Broadcasts run after the DB commit so the panel does not
 * refetch a stale row, and a Reverb failure must not fail the print agent.
 */
class PrintStatusBroadcaster
{
    public function job(PrintJob $job): void
    {
        $id = (int) $job->id;

        DB::afterCommit(function () use ($id) {
            try {
                $fresh = PrintJob::query()
                    ->with([
                        'credentialPrint:id,uuid,batch_uuid',
                        'student.profile:id,first_name,last_name',
                        'creator:id,name',
                        'canceller:id,name',
                        'cardDesign:id,faces_mode',
                    ])
                    ->find($id);
                if (! $fresh) {
                    return;
                }

                event(new PrintJobUpdated($this->jobPayload($fresh)));
            } catch (Throwable $e) {
                Log::warning('Print job broadcast failed', [
                    'print_job_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function card(CredentialPrint $card): void
    {
        $id = (int) $card->id;

        DB::afterCommit(function () use ($id) {
            try {
                $fresh = CredentialPrint::query()
                    ->with([
                        'student.profile:id,first_name,last_name',
                        'cardDesign:id,uuid,name',
                        'creator:id,name',
                        'discarder:id,name',
                        'printJobs.student.profile:id,first_name,last_name',
                        'printJobs.creator:id,name',
                        'printJobs.canceller:id,name',
                        'printJobs.cardDesign:id,faces_mode',
                        'printJobs.credentialPrint:id,uuid,batch_uuid',
                    ])
                    ->find($id);
                if (! $fresh) {
                    return;
                }

                event(new CredentialPrintUpdated($this->cardPayload($fresh)));
            } catch (Throwable $e) {
                Log::warning('Credential print broadcast failed', [
                    'credential_print_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function agent(array $status): void
    {
        DB::afterCommit(function () use ($status) {
            try {
                event(new PrintAgentUpdated($status));
            } catch (Throwable $e) {
                Log::warning('Print agent broadcast failed', [
                    'printer_id' => $status['printer_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(PrintJob $job): array
    {
        $profile = $job->student?->profile;
        $payload = is_array($job->payload_json) ? $job->payload_json : [];

        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'student_id' => $job->student_id,
            'student_name' => $profile
                ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
                : null,
            'credential_print_id' => $job->credential_print_id,
            'credential_print_uuid' => $job->credentialPrint?->uuid,
            'batch_uuid' => $job->credentialPrint?->batch_uuid,
            'printer_id' => $job->printer_id,
            'template_key' => $job->template_key,
            'design_key' => $payload['design_key'] ?? $job->template_key,
            'design_label' => $payload['design_label'] ?? null,
            'faces_mode' => $payload['faces_mode'] ?? $job->cardDesign?->faces_mode ?? 'single',
            'side_mode' => $job->side_mode,
            'status' => $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status,
            'attempts' => $job->attempts,
            'last_error' => $job->last_error,
            'claimed_by' => $job->claimed_by,
            'claimed_at' => $job->claimed_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
            'created_by_name' => $job->creator?->name,
            'cancelled_by_name' => $job->canceller?->name,
            'cancelled_at' => $job->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(CredentialPrint $card): array
    {
        $profile = $card->student?->profile;
        $jobs = $card->printJobs->sortBy('id')->values();
        $active = $card->activeJob();

        return [
            'id' => $card->id,
            'uuid' => $card->uuid,
            'student_id' => $card->student_id,
            'student_name' => $profile
                ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
                : null,
            'academic_year_id' => $card->academic_year_id,
            'card_design_id' => $card->card_design_id,
            'design_key' => $card->cardDesign?->uuid,
            'design_label' => $card->cardDesign?->name,
            'faces_mode' => $card->faces_mode,
            'front_status' => $this->enumValue($card->front_status),
            'back_status' => $this->enumValue($card->back_status),
            'strategy' => $this->enumValue($card->strategy),
            'batch_uuid' => $card->batch_uuid,
            'reason' => $this->enumValue($card->reason),
            'created_by' => $card->created_by,
            'created_by_name' => $card->creator?->name,
            'discarded_by' => $card->discarded_by,
            'discarded_by_name' => $card->discarder?->name,
            'discarded_at' => $card->discarded_at?->toIso8601String(),
            'discard_reason' => $card->discard_reason,
            'front_printed_at' => $card->front_printed_at?->toIso8601String(),
            'back_printed_at' => $card->back_printed_at?->toIso8601String(),
            'completed_at' => $card->completed_at?->toIso8601String(),
            'created_at' => $card->created_at?->toIso8601String(),
            'updated_at' => $card->updated_at?->toIso8601String(),
            'jobs' => $jobs->map(fn (PrintJob $job) => $this->jobPayload($job))->all(),
            'active_job' => $active ? $this->jobPayload($active) : null,
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? $value->value : (string) $value;
    }
}
