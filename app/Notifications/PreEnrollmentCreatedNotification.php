<?php

namespace App\Notifications;

use App\Enums\AppNotificationType;
use App\Models\PreEnrollment;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PreEnrollmentCreatedNotification extends Notification
{

    public function __construct(
        public PreEnrollment $preEnrollment,
        public string $source = 'public'
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $studentName = trim(implode(' ', array_filter([
            $this->preEnrollment->first_name,
            $this->preEnrollment->last_name,
            $this->preEnrollment->second_last_name,
        ])));

        $sourceLabel = $this->source === 'admin' ? 'panel administrativo' : 'formulario público';

        return [
            'type' => AppNotificationType::PreEnrollmentCreated->value,
            'title' => AppNotificationType::PreEnrollmentCreated->label(),
            'message' => "Se registró la preinscripción {$this->preEnrollment->folio} ({$studentName}) desde el {$sourceLabel}.",
            'action_url' => "/admissions/applications/{$this->preEnrollment->id}",
            'entity_type' => 'pre_enrollment',
            'entity_id' => $this->preEnrollment->id,
            'meta' => [
                'folio' => $this->preEnrollment->folio,
                'student_name' => $studentName,
                'source' => $this->source,
                'created_at' => $this->preEnrollment->created_at?->toIso8601String(),
            ],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
