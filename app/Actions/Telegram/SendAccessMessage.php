<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendAccessMessageWithCallbackJob;
use App\Models\BotUser;

/**
 * Одноразовая отправка и закрепление в личном чате сотрудника сообщения
 * с кнопками доступа к кодам и орг. информации
 */
class SendAccessMessage
{
    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        if ($botUser->platform !== 'telegram' || empty($botUser->chat_id)) {
            return;
        }

        if ($botUser->isBanned() || $botUser->hasAccessMessage()) {
            return;
        }

        $queryParams = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.access_message_text'),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => $this->getKeyboard(),
            ],
        ]);

        SendAccessMessageWithCallbackJob::dispatch($botUser->id, $queryParams);
    }

    /**
     * @return array
     */
    public function getKeyboard(): array
    {
        return [
            [
                ['text' => SafeCodeType::SAFE->buttonLabel(), 'callback_data' => SafeCodeType::SAFE->callbackData()],
            ],
            [
                ['text' => SafeCodeType::BUILDING->buttonLabel(), 'callback_data' => SafeCodeType::BUILDING->callbackData()],
            ],
            [
                ['text' => SafeCodeType::ORG_LINK->buttonLabel(), 'callback_data' => SafeCodeType::ORG_LINK->callbackData()],
            ],
        ];
    }
}
