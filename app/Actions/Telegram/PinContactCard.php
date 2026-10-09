<?php

namespace App\Actions\Telegram;

use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Закрепляет контактную карточку клиента в его теме, чтобы она всегда была перед глазами
 * (ФИО, телефон, статус доступа и кнопки управления)
 */
class PinContactCard
{
    /**
     * @param BotUser  $botUser
     * @param int|null $previousMessageId Прежняя карточка (если её заменили новым сообщением) — будет откреплена
     *
     * @return bool
     */
    public function execute(BotUser $botUser, ?int $previousMessageId = null): bool
    {
        if (empty($botUser->topic_id) || empty($botUser->contact_info_message_id)) {
            return false;
        }

        $groupId = config('traffic_source.settings.telegram.group_id');

        if ($previousMessageId && $previousMessageId !== (int)$botUser->contact_info_message_id) {
            TelegramMethods::sendQueryTelegram('unpinChatMessage', [
                'chat_id' => $groupId,
                'message_id' => $previousMessageId,
            ]);
        }

        $response = TelegramMethods::sendQueryTelegram('pinChatMessage', [
            'chat_id' => $groupId,
            'message_id' => (int)$botUser->contact_info_message_id,
            'disable_notification' => true,
        ]);

        if (!$response->ok) {
            Log::info('PinContactCard: не удалось закрепить карточку', [
                'bot_user_id' => $botUser->id,
                'error' => $response->rawData['description'] ?? null,
            ]);
        }

        return $response->ok === true;
    }
}
