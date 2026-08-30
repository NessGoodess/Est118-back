<?php

namespace App\Jobs;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * Low-priority broadcast of a published announcement to the school Telegram channel.
 */
class SendAnnouncementToTelegramChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 45;

    public function __construct(
        public int $announcementId
    ) {
        // Same default queue as the rest of the app (worker picks it up without extra flags)
        $this->onQueue((string) config('telegram.announcements.queue', 'default'));
    }

    public function handle(): void
    {
        $chatId = $this->normalizeChannelChatId(
            (string) config('telegram.announcements.chat_id', '')
        );

        if ($chatId === '') {
            Log::warning('Telegram announcement skipped: TELEGRAM_ANNOUNCEMENTS_CHAT_ID empty', [
                'announcement_id' => $this->announcementId,
            ]);

            return;
        }

        $announcement = Announcement::query()->find($this->announcementId);
        if (! $announcement) {
            return;
        }

        if (! $announcement->published_at || $announcement->published_at->isFuture()) {
            return;
        }

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $slugOrId = $announcement->slug ?: (string) $announcement->id;
        $url = "{$frontend}/Announcements/{$slugOrId}";

        $summary = trim((string) ($announcement->summary ?? ''));
        if (mb_strlen($summary) > 280) {
            $summary = mb_substr($summary, 0, 277).'…';
        }

        $header = trim((string) ($announcement->header ?? ''));
        $lines = [
            '📢 <b>'.e($announcement->title).'</b>',
        ];
        if ($header !== '') {
            $lines[] = '<i>'.e($header).'</i>';
        }
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = e($summary);
        }
        $lines[] = '';
        $lines[] = '👉 <a href="'.e($url).'">Leer aviso completo</a>';
        $lines[] = '';
        $lines[] = '<i>Ver mas comunicados en <a href="https://www.facebook.com/EscSecTecnica118">Facebook</a></i>';

        $text = implode("\n", $lines);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ]);

        Log::info('Telegram announcement sent successfully', [
            'announcement_id' => $announcement->id,
            'title' => $announcement->title,
            'url' => $url,
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Channel ids must be negative (e.g. -1003847990874).
     */
    private function normalizeChannelChatId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (! str_starts_with($raw, '-')) {
            $raw = '-'.$raw;
        }

        return $raw;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed Telegram announcement broadcast', [
            'announcement_id' => $this->announcementId,
            'error' => $exception->getMessage(),
        ]);
    }
}
