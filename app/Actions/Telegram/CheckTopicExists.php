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
            // Используем sendMessage с невидимым символом для проверки существования топика
            // Это не изменяет иконку топика, в отличие от editForumTopic
            // Сообщение будет сразу удалено, если топик существует
            $response = TelegramMethods::sendQueryTelegram('sendMessage', [
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'message_thread_id' => $topicId,
                'text' => "\u{200B}", // Невидимый символ (zero-width space)
            ]);

            // Edge case: проверяем, что response не null
            if ($response === null) {
                \Log::warning('CheckTopicExists: response is null', [
                    'topic_id' => $topicId,
                ]);
                return true; // Считаем, что топик существует, чтобы не ломать работу
            }

            // Если ответ успешный - топик существует, удаляем тестовое сообщение
            if ($response->ok === true) {
                // Edge case: проверяем наличие message_id перед удалением
                if (!empty($response->message_id)) {
                    // Удаляем тестовое сообщение асинхронно, чтобы не блокировать выполнение
                    // Если удаление не удастся - не критично, это тестовое сообщение
                    \App\Jobs\SendTelegramSimpleQueryJob::dispatch(\App\DTOs\TGTextMessageDto::from([
                        'methodQuery' => 'deleteMessage',
                        'chat_id' => config('traffic_source.settings.telegram.group_id'),
                        'message_id' => $response->message_id,
                    ]))->delay(now()->addSeconds(0.5)); // Небольшая задержка перед удалением
                } else {
                    // Edge case: успешный ответ, но message_id отсутствует
                    \Log::warning('CheckTopicExists: успешный ответ, но message_id отсутствует', [
                        'topic_id' => $topicId,
                        'response' => $response->rawData ?? null,
                    ]);
                }
                return true;
            }

            // Если ошибка TOPIC_NOT_FOUND или TOPIC_DELETED - топик не существует
            if ($response->response_code === 400 && 
                ($response->type_error === 'TOPIC_NOT_FOUND' || $response->type_error === 'TOPIC_DELETED')) {
                return false;
            }

            // Edge case: топик закрыт - все равно существует, но может быть недоступен для записи
            if ($response->response_code === 400 && $response->type_error === 'TOPIC_CLOSED') {
                \Log::debug('CheckTopicExists: топик закрыт, но существует', [
                    'topic_id' => $topicId,
                ]);
                return true; // Топик существует, просто закрыт
            }

            // Edge case: недостаточно прав - топик может существовать, но бот не может писать
            if ($response->response_code === 403) {
                \Log::warning('CheckTopicExists: недостаточно прав для отправки сообщения в топик', [
                    'topic_id' => $topicId,
                ]);
                return true; // Считаем, что топик существует, но есть проблемы с правами
            }

            // Для других ошибок считаем, что топик существует (может быть другая проблема)
            // Например, другие ошибки API
            \Log::debug('CheckTopicExists: неизвестная ошибка, считаем топик существующим', [
                'topic_id' => $topicId,
                'response_code' => $response->response_code ?? null,
                'type_error' => $response->type_error ?? null,
            ]);
            return true;
        } catch (\Throwable $e) {
            // В случае исключения считаем, что топик существует (не хотим ломать работу)
            // Логируем для отладки
            \Log::warning('Ошибка при проверке существования топика', [
                'topic_id' => $topicId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return true;
        }
    }
}

