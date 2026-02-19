<?php

namespace App\Actions\Telegram;

use Illuminate\Support\Facades\Log;

/**
 * Проверка, является ли топик топиком массовых рассылок
 */
class IsBroadcastTopic
{
    /**
     * Проверяет, является ли топик топиком массовых рассылок
     *
     * @param int|null $messageThreadId
     *
     * @return bool
     */
    public static function execute(?int $messageThreadId): bool
    {
        try {
            // Проверяем наличие конфигурации
            $broadcastTopicId = config('traffic_source.settings.telegram.broadcast_topic_id');
            
            if (empty($broadcastTopicId)) {
                // Конфиг не задан - не является топиком массовых рассылок
                return false;
            }

            // Проверяем, что messageThreadId не пустой
            if (empty($messageThreadId)) {
                return false;
            }

            // Сравниваем с конфигурационным значением
            $isBroadcast = (int)$messageThreadId === (int)$broadcastTopicId;

            if ($isBroadcast) {
                Log::debug('IsBroadcastTopic: сообщение из топика массовых рассылок', [
                    'message_thread_id' => $messageThreadId,
                    'broadcast_topic_id' => $broadcastTopicId,
                ]);
            }

            return $isBroadcast;
        } catch (\Throwable $e) {
            Log::warning('IsBroadcastTopic: ошибка при проверке топика массовых рассылок', [
                'message_thread_id' => $messageThreadId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

