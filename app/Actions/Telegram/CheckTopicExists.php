<?php

namespace App\Actions\Telegram;

use App\TelegramBot\TelegramMethods;

/**
 * Проверка существования топика
 */
class CheckTopicExists
{
    /**
     * Проверяет, существует ли топик
     *
     * @param int $topicId
     *
     * @return bool
     */
    public static function execute(int $topicId): bool
    {
        try {
            // Пытаемся обновить только иконку топика (без изменения названия)
            // Если топик не существует, вернется ошибка TOPIC_NOT_FOUND
            // Используем текущую иконку, чтобы не изменять топик
            $response = TelegramMethods::sendQueryTelegram('editForumTopic', [
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'message_thread_id' => $topicId,
                'icon_custom_emoji_id' => __('icons.incoming'), // Используем стандартную иконку
            ]);

            // Если ответ успешный - топик существует
            if ($response->ok === true) {
                return true;
            }

            // Если ошибка TOPIC_NOT_FOUND или TOPIC_DELETED - топик не существует
            if ($response->response_code === 400 && 
                ($response->type_error === 'TOPIC_NOT_FOUND' || $response->type_error === 'TOPIC_DELETED')) {
                return false;
            }

            // Для других ошибок считаем, что топик существует (может быть другая проблема)
            // Например, недостаточно прав или другая ошибка API
            return true;
        } catch (\Throwable $e) {
            // В случае исключения считаем, что топик существует (не хотим ломать работу)
            // Логируем для отладки
            \Log::warning('Ошибка при проверке существования топика', [
                'topic_id' => $topicId,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }
}

