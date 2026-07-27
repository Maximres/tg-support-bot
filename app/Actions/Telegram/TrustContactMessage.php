<?php

namespace App\Actions\Telegram;

use App\Models\BotUser;

class TrustContactMessage
{
    /**
     * Выдать или отозвать доступ пользователя к коду сейфа
     *
     * @param BotUser $botUser
     * @param bool    $trustStatus
     *
     * @return void
     */
    public function execute(BotUser $botUser, bool $trustStatus): void
    {
        $botUser->update([
            'is_trusted' => $trustStatus,
            'trusted_at' => $trustStatus ? now() : null,
        ]);

        // Обновляем контактную карточку, чтобы кнопка отобразила новое состояние
        (new UpdateContactMessage())->execute($botUser);
    }
}
