<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;

/**
 * Send contact message
 */
class SendContactMessage
{
    /**
     * Send contact message
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        $queryParams = $this->getQueryParams($botUser);
        SendTelegramSimpleQueryJob::dispatch($queryParams);
    }

    /**
     * @param BotUser $botUser
     *
     * @return TGTextMessageDto
     */
    public function getQueryParams(BotUser $botUser): TGTextMessageDto
    {
        return TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'text' => $this->createContactMessage($botUser->chat_id, $botUser->platform, $botUser->phone_number, $botUser->full_name, $botUser->email, $botUser->isBanned()),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => $this->getKeyboard($botUser),
            ],
        ]);
    }

    /**
     * Create contact message
     *
     * @param int    $chatId
     * @param string $platform
     * @param string|null $phoneNumber
     * @param string|null $fullName
     * @param string|null $email
     *
     * @return string
     */
    public function createContactMessage(int $chatId, string $platform, ?string $phoneNumber = null, ?string $fullName = null, ?string $email = null, bool $isBanned = false): string
    {
        try {
            $textMessage = '';
            
            // Добавляем статус блокировки, если пользователь заблокирован
            if ($isBanned) {
                $textMessage .= "<b>🚫 ПОЛЬЗОВАТЕЛЬ ЗАБЛОКИРОВАН 🚫</b> \n\n";
            }
            
            $textMessage .= "<b>КОНТАКТНАЯ ИНФОРМАЦИЯ</b> \n";
            $textMessage .= "Источник: {$platform} \n";
            $textMessage .= "ID: {$chatId} \n";

            if ($platform === 'telegram') {
                $chat = GetChat::execute($chatId);
                $chatData = $chat->rawData;
                if (!empty($chatData['result']['username'])) {
                    $link = "https://telegram.me/{$chatData['result']['username']}";
                    $textMessage .= "Ссылка: {$link} \n";
                }
            }
            
            // Добавляем ФИО, если есть
            if (!empty($fullName)) {
                $textMessage .= "ФИО: <b>{$fullName}</b> \n";
            }
            
            // Добавляем номер телефона, если он есть
            if (!empty($phoneNumber)) {
                $textMessage .= "Телефон: <b>{$phoneNumber}</b> \n";
            }
            
            // Добавляем email, если он есть
            if (!empty($email)) {
                $textMessage .= "Email: <b>{$email}</b> \n";
            }
            
            return $textMessage;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param BotUser $botUser
     *
     * @return array
     */
    public function getKeyboard(BotUser $botUser): array
    {
        if ($botUser->isBanned()) {
            $banButton = [
                'text' => __('messages.but_ban_user_false'),
                'callback_data' => 'topic_user_ban_false',
            ];
        } else {
            $banButton = [
                'text' => __('messages.but_ban_user_true'),
                'callback_data' => 'topic_user_ban_true',
            ];
        }

        $keyboard = [
            [
                $banButton,
            ],
        ];

        // Добавляем кнопку запроса номера, если его еще нет
        if (empty($botUser->phone_number)) {
            $keyboard[] = [
                [
                    'text' => __('messages.but_request_phone_from_group'),
                    'callback_data' => 'request_phone_from_group',
                ],
            ];
        }

        $keyboard[] = [
            [
                'text' => __('messages.but_close_topic'),
                'callback_data' => 'close_topic',
            ],
        ];

        return $keyboard;
    }
}
