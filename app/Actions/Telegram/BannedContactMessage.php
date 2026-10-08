<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;

class BannedContactMessage
{
    /**
     * @param BotUser  $botUser
     * @param bool     $banStatus
     * @param int|null $messageId
     *
     * @return void
     */
    public function execute(BotUser $botUser, bool $banStatus, ?int $messageId = null): void
    {
        $botUser->update([
            'is_banned' => $banStatus,
        ]);
        $botUser->save();

        // Меню клиента сворачивается при блокировке и возвращается при разблокировке (если доступ открыт)
        (new RefreshAccessMessage())->execute($botUser);

        // Используем UpdateContactMessage для обновления контактного сообщения
        // Это автоматически обновит сообщение с учетом статуса блокировки
        (new UpdateContactMessage())->execute($botUser);

        if ($banStatus) {
            $this->remindAboutRotation($botUser);
        }
    }

    /**
     * Клиент мог запомнить коды и ссылки — напоминаем администраторам о ротации и даём кнопку
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    private function remindAboutRotation(BotUser $botUser): void
    {
        if (empty($botUser->topic_id)) {
            return;
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'text' => __('messages.ban_rotation_reminder'),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('messages.panel.but_rotate_after_ban'), 'callback_data' => AdminPanel::ROTATE_CALLBACK],
                    ],
                ],
            ],
        ]));
    }
}
