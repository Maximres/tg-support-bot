<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;

class CloseTopic
{
    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        // Edge case: проверяем, что topic_id не null
        if (empty($botUser->topic_id)) {
            \Illuminate\Support\Facades\Log::warning('CloseTopic: topic_id пустой, пропускаем закрытие топика', [
                'bot_user_id' => $botUser->id ?? null,
            ]);
            return;
        }

        $groupId = config('traffic_source.settings.telegram.group_id');

        // Клиенту о закрытии обращения не сообщаем: для него переписка остаётся обычной,
        // а при новом сообщении тема откроется сама

        $iconOutgoing = __('icons.outgoing');
        
        // Edge case: проверяем, что иконка не пустая
        if (!empty($iconOutgoing)) {
            // Добавляем задержку для предотвращения конфликтов при параллельных обновлениях
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'editForumTopic',
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'icon_custom_emoji_id' => $iconOutgoing,
            ]))->delay(now()->addSeconds(1));
        }

        // Запоминаем, что обращение закрыто: если клиент напишет снова, топик откроется автоматически
        $botUser->markTopicClosed();

        // Закрытие топика выполняется после обновления иконки
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'closeForumTopic',
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
        ]))->delay(now()->addSeconds(2));
    }
}
