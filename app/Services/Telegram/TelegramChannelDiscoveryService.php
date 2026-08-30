<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * Resolve announcements channel numeric chat_id from a private command.
 *
 * Telegram hides "from" on many channel posts and often strips forward origin,
 * so the reliable path is: ask the bot in DM → bot calls getChat(@username).
 */
class TelegramChannelDiscoveryService
{
    private const PRIVATE_COMMANDS = [
        '/private_channel_id',
        '/channel_id',
        '/chatid',
    ];

    /**
     * Handle /private_channel_id [username] in a private chat with the bot.
     */
    public function handlePrivateCommand($update): bool
    {
        $message = $update->getMessage();
        if (! $message) {
            return false;
        }

        $text = trim((string) ($message->text ?? ''));
        if ($text === '' || ! $this->isPrivateDiscoveryCommand($text)) {
            return false;
        }

        $privateChatId = $message->getChat()?->id;
        if ($privateChatId === null) {
            return false;
        }

        $username = $this->extractUsernameArgument($text)
            ?? $this->usernameFromInviteLinkConfig();

        if ($username === null) {
            Telegram::sendMessage([
                'chat_id' => $privateChatId,
                'text' => "Uso:\n".
                    "/private_channel_id @tu_canal\n".
                    "o\n".
                    "/private_channel_id tu_canal\n\n".
                    "También puedes dejar TELEGRAM_ANNOUNCEMENTS_INVITE_LINK=https://t.me/tu_canal ".
                    "luego solo enviar /private_channel_id\n\n".
                    "Nota: el canal debe tener @username público (no solo link t.me/+…). ".
                    "El bot debe ser administrador del canal.",
            ]);

            return true;
        }

        try {
            $chat = Telegram::getChat(['chat_id' => '@'.$username]);
            $channelId = $chat->id ?? null;
            $title = $chat->title ?? $username;
            $type = $chat->type ?? 'channel';

            if ($channelId === null) {
                throw new \RuntimeException('getChat returned no id');
            }

            Telegram::sendMessage([
                'chat_id' => $privateChatId,
                'text' => "📢 Canal encontrado: {$title}\n\n".
                    "Este es el id numérico del canal (para publicar avisos):\n\n".
                    "TELEGRAM_ANNOUNCEMENTS_CHAT_ID={$channelId}\n\n".
                    "Username: @{$username}\n".
                    "Tipo: {$type}",
            ]);

            Log::info('Resolved announcements channel id via getChat', [
                'username' => $username,
                'channel_chat_id' => $channelId,
                'asked_by' => $privateChatId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('getChat failed for announcements channel', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            Telegram::sendMessage([
                'chat_id' => $privateChatId,
                'text' => "No pude leer el canal @{$username}.\n\n".
                    "Revisa que:\n".
                    "• El @username sea correcto\n".
                    "• El bot sea administrador del canal\n".
                    "• No sea solo un link privado t.me/+… (ahí no hay username para getChat)\n\n".
                    "Error: ".$e->getMessage(),
            ]);
        }

        return true;
    }

    public function isPrivateDiscoveryCommand(string $text): bool
    {
        $command = $this->parseCommandName($text);

        return in_array($command, self::PRIVATE_COMMANDS, true);
    }

    private function parseCommandName(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $first = explode(' ', $text)[0] ?? '';

        return explode('@', $first)[0];
    }

    private function extractUsernameArgument(string $text): ?string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        if (count($parts) < 2 || trim($parts[1]) === '') {
            return null;
        }

        return $this->normalizeUsername($parts[1]);
    }

    private function usernameFromInviteLinkConfig(): ?string
    {
        $link = trim((string) config('telegram.announcements.invite_link', ''));
        if ($link === '') {
            return null;
        }

        if (preg_match('~(?:t\.me|telegram\.me)/([A-Za-z0-9_]+)$~', $link, $m)) {
            $user = $m[1];
            // invite hashes look like +AbCd or joinchat
            if (str_starts_with($user, '+') || strcasecmp($user, 'joinchat') === 0) {
                return null;
            }

            return $this->normalizeUsername($user);
        }

        return null;
    }

    private function normalizeUsername(string $value): ?string
    {
        $value = trim($value);
        $value = ltrim($value, '@');
        $value = preg_replace('~^https?://(t\.me|telegram\.me)/~i', '', $value) ?? $value;
        $value = explode('?', $value)[0];
        $value = trim($value, '/');

        if ($value === '' || str_starts_with($value, '+')) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_]{4,}$/', $value)) {
            return null;
        }

        return $value;
    }
}
