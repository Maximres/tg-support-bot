<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;

/**
 * Отправка стартового сообщения
 */
class SendStartMessage
{
    /**
     * Отправка стартового сообщения
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update): void
    {
        TelegramMethods::sendQueryTelegram('deleteMessage', [
            'chat_id' => $update->chatId,
            'message_id' => $update->messageId,
        ]);

        if ($update->typeSource === 'private') {
            $messageParamsDTO = TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'text' => __('messages.start'),
                'parse_mode' => 'html',
                'reply_markup' => [
                    'keyboard' => [
                        [
                            [
                                'text' => __('messages.but_request_phone'),
                                'request_contact' => true,
                            ],
                        ],
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ],
            ]);

            $botUser = BotUser::getOrCreateByTelegramUpdate($update);

            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                $messageParamsDTO,
                'outgoing'
            );
        }
    }
}
