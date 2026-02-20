<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;

/**
 * Отправка сообщения с запросом номера телефона
 */
class SendPhoneRequestMessage
{
    /**
     * Отправка сообщения с кнопкой запроса номера телефона
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
            $botUser = BotUser::getOrCreateByTelegramUpdate($update);
            
            // Если номер уже есть, сообщаем об этом
            if (!empty($botUser->phone_number)) {
                $messageParamsDTO = TGTextMessageDto::from([
                    'methodQuery' => 'sendMessage',
                    'chat_id' => $update->chatId,
                    'text' => __('messages.phone_already_provided', ['phone' => $botUser->phone_number]),
                    'parse_mode' => 'html',
                ]);

                SendTelegramMessageJob::dispatch(
                    $botUser->id,
                    $update,
                    $messageParamsDTO,
                    'outgoing'
                );
                return;
            }

            // Отправляем сообщение с кнопкой запроса номера
            $messageParamsDTO = TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'text' => __('messages.request_phone_message'),
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

            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                $messageParamsDTO,
                'outgoing'
            );
        }
    }
}

