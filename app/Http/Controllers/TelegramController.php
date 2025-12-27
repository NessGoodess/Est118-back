<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramFlowService;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramController extends Controller
{
    public function __construct(
        private TelegramFlowService $flow
    ) {}

    public function webhook()
    {
        $update = Telegram::getWebhookUpdate();

        if (!$update->getMessage() && !$update->getCallbackQuery()) {
            return response('ok', 200);
        }

        $this->flow->handle($update);

        return response('ok', 200);
    }
}
