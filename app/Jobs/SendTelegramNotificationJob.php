<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram;

class SendTelegramNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Seconds between retries.
     */
    public int $backoff = 30;

    /**
     * Timeout in seconds.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @param  string  $type  'entry' | 'exit'
     */
    public function __construct(
        public string $telegramId,
        public array $studentData,
        public \DateTimeInterface $registrationTime,
        public string $type = 'entry'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $photoPath = storage_path('app/private/banners/Black_Banner.png');

        Telegram::sendPhoto([
            'chat_id' => $this->telegramId,
            'photo' => InputFile::create($photoPath),
            'parse_mode' => 'HTML',
            'caption' => $this->buildCaption(),
        ]);

        Log::info('Telegram notification sent', [
            'telegram_id' => $this->telegramId,
            'student' => $this->studentData['name'] ?? 'unknown',
        ]);
    }

    /**
     * Build the notification caption.
     */
    private function buildCaption(): string
    {
        $s = $this->studentData;
        $t = $this->registrationTime;
        $isEntry = $this->type === 'entry';

        $title = $isEntry ? '✅ ENTRADA A LA ESCUELA' : '🚪 SALIDA DE LA ESCUELA';
        $icon = $isEntry ? '🏫' : '🏃';
        $horaLabel = $isEntry ? 'Hora de entrada' : 'Hora de salida';
        $mensaje = $isEntry ? 'ha ingresado a la institución' : 'ha salido de la institución';

        return "{$icon} <b>{$title}</b>\n\n" .
            "Estimado(a) padre/madre de familia:\n\n" .
            "Su hijo(a):\n\n" .
            "<b>👨‍🎓 {$s['name']}</b>\n" .
            "<b>📘 Grado y Grupo:</b> {$s['grade']} \"{$s['group']}\"\n\n" .
            "<b>🕘 {$horaLabel}:</b> {$t->format('H:i:s')}\n" .
            "<b>📅 Fecha:</b> {$t->format('d/m/Y')}\n\n" .
            "<b>{$mensaje}</b>\n\n" .
            "<i>Seguimos trabajando por la seguridad de nuestros alumnos.</i>";
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to send Telegram notification', [
            'telegram_id' => $this->telegramId,
            'student' => $this->studentData['name'] ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);
    }
}
