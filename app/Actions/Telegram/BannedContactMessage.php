<?php

namespace App\Actions\Telegram;

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

        // Используем UpdateContactMessage для обновления контактного сообщения
        // Это автоматически обновит сообщение с учетом статуса блокировки
        (new UpdateContactMessage())->execute($botUser);
    }
}
