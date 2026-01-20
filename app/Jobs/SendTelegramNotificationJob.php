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
     */
    public function __construct(
        public string $telegramId,
        public array $studentData,
        public \DateTimeInterface $registrationTime
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

        return "👋 <b>Estimado(a) padre/madre de familia:</b>\n\n" .
            "Le informamos que su hijo(a):\n\n" .
            "<b>👨‍🎓 {$s['name']}</b>\n" .
            "<b>📘 Grado y Grupo:</b> {$s['grade']} \"{$s['group']}\"\n\n" .
            "<b>🕘 Hora de ingreso:</b> {$t->format('H:i:s')}\n" .
            "<b>📅 Fecha:</b> {$t->format('d/m/Y')}\n\n" .
            "<b>✅ Asistencia registrada correctamente.</b>\n\n" .
            "<i>Seguimos trabajando por la seguridad y el bienestar de nuestros alumnos.</i>";
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
