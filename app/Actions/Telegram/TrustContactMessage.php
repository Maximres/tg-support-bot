<?php

namespace App\Actions\Telegram;

use App\Models\BotUser;

class TrustContactMessage
{
    /**
     * Открыть или отозвать доступ клиента к материалам (ссылки, коды, орг. информация)
     *
     * @param BotUser $botUser
     * @param bool    $trustStatus
     *
     * @return void
     */
    public function execute(BotUser $botUser, bool $trustStatus): void
    {
        $changed = $botUser->isTrusted() !== $trustStatus;

        $botUser->update([
            'is_trusted' => $trustStatus,
            'trusted_at' => $trustStatus ? now() : null,
        ]);

        // Меню клиента подстраивается под новый доступ; сообщаем о смене только если она реально произошла
        (new RefreshAccessMessage())->execute($botUser, $changed);

        // Обновляем контактную карточку, чтобы кнопка отобразила новое состояние
        (new UpdateContactMessage())->execute($botUser);
    }
}
