<?php

namespace App\Actions\Telegram;

use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;

/**
 * Оставляет в закрепах личного чата клиента только актуальное меню бота (BotUser::access_message_id):
 * старые копии открепляются, а видимую сверху — ещё и удаляется.
 *
 * Telegram отдаёт боту только верхнее закреплённое сообщение, поэтому перечислить и удалить все старые копии
 * нельзя: сняв все закрепы одним вызовом, бот закрепляет актуальное меню заново (в чате при этом появляется служебная
 * строка «бот закрепил сообщение»). Поэтому:
 *  - обычный режим срабатывает, только если сверху закреплено что-то кроме актуального меню;
 *  - глубокий режим (deep) чистит закрепы и тогда, когда сверху актуальное меню (под ним могут быть копии).
 * Если в чате ничего не закреплено или сверху закреп самого клиента — ничего не трогаем.
 */
class PruneOldMenuPins
{
    /**
     * @param BotUser $botUser
     * @param bool    $deep
     *
     * @return bool true, если закрепы в чате были пересобраны
     */
    public function execute(BotUser $botUser, bool $deep = false): bool
    {
        $chatId = (int)$botUser->chat_id;
        $current = (int)$botUser->access_message_id;

        if ($chatId === 0 || $current === 0) {
            return false;
        }

        $response = TelegramMethods::sendQueryTelegram('getChat', ['chat_id' => $chatId]);
        $pinned = $response->rawData['result']['pinned_message'] ?? null;

        // Ничего не закреплено (клиент мог открепить сам) или закреплено не ботом — не вмешиваемся
        if (!$response->ok || empty($pinned['message_id']) || empty($pinned['from']['is_bot'])) {
            return false;
        }

        $top = (int)$pinned['message_id'];

        if ($top === $current && !$deep) {
            return false;
        }

        if ($top !== $current) {
            $this->remove($chatId, $top);
        }

        TelegramMethods::sendQueryTelegram('unpinAllChatMessages', ['chat_id' => $chatId]);
        TelegramMethods::sendQueryTelegram('pinChatMessage', [
            'chat_id' => $chatId,
            'message_id' => $current,
            'disable_notification' => true,
        ]);

        return true;
    }

    /**
     * Удаляет копию; если Telegram не даёт (слишком старая) — хотя бы убирает кнопки
     *
     * @param int $chatId
     * @param int $messageId
     *
     * @return void
     */
    private function remove(int $chatId, int $messageId): void
    {
        $deleted = TelegramMethods::sendQueryTelegram('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);

        if (!$deleted->ok) {
            TelegramMethods::sendQueryTelegram('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }
    }
}
