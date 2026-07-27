<?php

namespace App\Actions\Telegram;

use App\TelegramBot\TelegramMethods;

/**
 * Проверка того, что отправитель команды — реальный администратор группы Telegram
 */
class VerifyGroupAdmin
{
    /**
     * @param int      $groupId
     * @param int|null $userId
     *
     * @return bool|null true — администратор/создатель, false — точно не администратор,
     *                    null — не удалось проверить (ошибка обращения к Telegram API)
     */
    public static function check(int $groupId, ?int $userId): ?bool
    {
        if (empty($userId)) {
            return false;
        }

        $response = TelegramMethods::sendQueryTelegram('getChatMember', [
            'chat_id' => $groupId,
            'user_id' => $userId,
        ]);

        if (!$response->ok) {
            return null;
        }

        $status = $response->rawData['result']['status'] ?? null;

        return in_array($status, ['administrator', 'creator'], true);
    }
}
