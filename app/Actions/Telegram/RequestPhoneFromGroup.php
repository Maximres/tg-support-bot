<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use Illuminate\Support\Facades\Log;

/**
 * Запрос номера телефона клиента из группы администратором
 */
class RequestPhoneFromGroup
{
    /**
     * Отправить клиенту запрос на предоставление номера телефона
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        try {
            // Edge case: проверяем наличие пользователя
            if (empty($botUser) || empty($botUser->chat_id)) {
                Log::warning('RequestPhoneFromGroup: BotUser не найден или chat_id пустой', [
                    'bot_user_id' => $botUser->id ?? null,
                ]);
                return;
            }

            // Edge case: если номер уже есть, сообщаем об этом в группе
            if (!empty($botUser->phone_number)) {
                // Проверяем наличие topic_id перед отправкой сообщения в группу
                if (!empty($botUser->topic_id)) {
                    $groupId = config('traffic_source.settings.telegram.group_id');
                    SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                        'methodQuery' => 'sendMessage',
                        'chat_id' => $groupId,
                        'message_thread_id' => $botUser->topic_id,
                        'text' => __('messages.phone_already_in_group', ['phone' => $botUser->phone_number]),
                        'parse_mode' => 'html',
                    ]));
                }
                return;
            }

            // Отправляем клиенту сообщение с кнопкой запроса номера
            // Используем SendTelegramSimpleQueryJob для прямой отправки в private чат
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $botUser->chat_id,
                'text' => __('messages.request_phone_from_group'),
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
            ]));

            // Подтверждаем в группе, что запрос отправлен (только если есть topic_id)
            if (!empty($botUser->topic_id)) {
                $groupId = config('traffic_source.settings.telegram.group_id');
                SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                    'methodQuery' => 'sendMessage',
                    'chat_id' => $groupId,
                    'message_thread_id' => $botUser->topic_id,
                    'text' => __('messages.phone_request_sent'),
                    'parse_mode' => 'html',
                ]));
            }

            Log::info('RequestPhoneFromGroup: запрос номера отправлен клиенту', [
                'bot_user_id' => $botUser->id,
                'chat_id' => $botUser->chat_id,
            ]);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('RequestPhoneFromGroup: ошибка при отправке запроса номера', [
                'error' => $e->getMessage(),
                'bot_user_id' => $botUser->id ?? null,
            ]);
        }
    }
}

