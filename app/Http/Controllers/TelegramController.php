<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramChannelDiscoveryService;
use App\Services\Telegram\TelegramFlowService;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramController extends Controller
{
    public function __construct(
        private TelegramFlowService $flow,
        private TelegramChannelDiscoveryService $channelDiscovery
    ) {}

    public function webhook()
    {
        $update = Telegram::getWebhookUpdate();

        // If the update is not a message or a callback query, return ok
        if (! $update->getMessage() && ! $update->getCallbackQuery()) {
            return response('ok', 200);
        }

        // Channel/group updates must not run vinculación
        if (! $this->isPrivateChat($update)) {
            return response('ok', 200);
        }

        // If the update is a private command, handle it
        if ($this->channelDiscovery->handlePrivateCommand($update)) {
            return response('ok', 200);
        }

        $this->flow->handle($update);

        return response('ok', 200);
    }

    private function isPrivateChat($update): bool
    {
        return $this->resolveChatType($update) === 'private';
    }

    private function resolveChatType($update): ?string
    {
        $chat = $update->getMessage()
            ? $update->getMessage()->getChat()
            : $update->getCallbackQuery()?->getMessage()?->getChat();

        return $chat?->type;
    }

    private function resolveChatId($update): int|string|null
    {
        $chat = $update->getMessage()
            ? $update->getMessage()->getChat()
            : $update->getCallbackQuery()?->getMessage()?->getChat();

        return $chat?->id;
    }
}
