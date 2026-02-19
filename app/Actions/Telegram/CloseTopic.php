<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\DTOs\Vk\VkTextMessageDto;
use App\Jobs\SendMessage\SendVkSimpleMessageJob;
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

        switch ($botUser->platform) {
            case 'telegram':
                $this->sendMessageInTelegram($botUser);
                break;

            case 'vk':
                $this->sendMessageInVk($botUser);
                break;
        }

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

        // Закрытие топика выполняется после обновления иконки
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'closeForumTopic',
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
        ]))->delay(now()->addSeconds(2));
    }

    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function sendMessageInTelegram(BotUser $botUser): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.message_close_topic'),
            'parse_mode' => 'html',
        ]));
    }

    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function sendMessageInVk(BotUser $botUser): void
    {
        SendVkSimpleMessageJob::dispatch(
            VkTextMessageDto::from([
                'methodQuery' => 'messages.send',
                'peer_id' => $botUser->chat_id,
                'message' => __('messages.message_close_topic'),
            ]),
        );
    }
}
