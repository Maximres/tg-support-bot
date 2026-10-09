<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Models\Message;

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

        if ($changed && $trustStatus) {
            $this->markWaitingForClient($botUser);
        }
    }

    /**
     * Новый клиент зарегистрировался, и администратор открыл ему доступ: мы ответили и ждём сообщения от клиента,
     * поэтому значок темы — ✅ (а не 💬 «клиент ждёт ответа»). Если клиент уже писал и ответа ещё нет,
     * значок не трогаем: его ведёт обычная логика ответов.
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    private function markWaitingForClient(BotUser $botUser): void
    {
        if (empty($botUser->topic_id)) {
            return;
        }

        $hasIncomingMessages = Message::where('bot_user_id', $botUser->id)
            ->where('message_type', 'incoming')
            ->exists();

        if ($hasIncomingMessages) {
            return;
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'editForumTopic',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'icon_custom_emoji_id' => __('icons.outgoing'),
        ]));
    }
}
