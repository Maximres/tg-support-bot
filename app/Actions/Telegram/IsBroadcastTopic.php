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
            
            // Логируем для отладки
            Log::debug('IsBroadcastTopic: проверка топика', [
                'message_thread_id' => $messageThreadId,
                'broadcast_topic_id_from_config' => $broadcastTopicId,
                'broadcast_topic_id_type' => gettype($broadcastTopicId),
            ]);
            
            // Проверяем, что конфиг задан (null, 0, '', false считаются пустыми)
            if ($broadcastTopicId === null || $broadcastTopicId === '' || $broadcastTopicId === false) {
                Log::debug('IsBroadcastTopic: конфиг не задан или пустой', [
                    'broadcast_topic_id' => $broadcastTopicId,
                ]);
                return false;
            }

            // Проверяем, что messageThreadId не пустой
            if ($messageThreadId === null || $messageThreadId === 0) {
                Log::debug('IsBroadcastTopic: messageThreadId пустой', [
                    'message_thread_id' => $messageThreadId,
                ]);
                return false;
            }

            // Сравниваем с конфигурационным значением (приводим к int для надежности)
            $isBroadcast = (int)$messageThreadId === (int)$broadcastTopicId;

            if ($isBroadcast) {
                Log::info('IsBroadcastTopic: сообщение из топика массовых рассылок', [
                    'message_thread_id' => $messageThreadId,
                    'broadcast_topic_id' => $broadcastTopicId,
                ]);
            } else {
                Log::debug('IsBroadcastTopic: топик не совпадает', [
                    'message_thread_id' => $messageThreadId,
                    'broadcast_topic_id' => $broadcastTopicId,
                    'message_thread_id_int' => (int)$messageThreadId,
                    'broadcast_topic_id_int' => (int)$broadcastTopicId,
                ]);
            }

            return $isBroadcast;
        } catch (\Throwable $e) {
            Log::warning('IsBroadcastTopic: ошибка при проверке топика массовых рассылок', [
                'message_thread_id' => $messageThreadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}

