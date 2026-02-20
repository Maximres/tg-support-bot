<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\DTOs\TelegramUpdateDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use Illuminate\Support\Facades\Log;

class RenameTopic
{
    /**
     * Переименовать топик
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update): void
    {
        try {
            // Проверяем, что команда вызвана в топике
            if (empty($update->messageThreadId)) {
                Log::warning('RenameTopic: messageThreadId пустой', [
                    'chat_id' => $update->chatId,
                ]);
                return;
            }

            // Получаем BotUser по topic_id
            $botUser = BotUser::getByTopicId($update->messageThreadId);
            if (!$botUser) {
                Log::warning('RenameTopic: BotUser не найден', [
                    'message_thread_id' => $update->messageThreadId,
                ]);
                return;
            }

            // Проверяем, что topic_id существует
            if (empty($botUser->topic_id)) {
                Log::warning('RenameTopic: topic_id пустой', [
                    'bot_user_id' => $botUser->id ?? null,
                ]);
                return;
            }
            
            // Убеждаемся, что sequential_number присвоен (если нет, присваиваем)
            if ($botUser->sequential_number === null) {
                $botUser->assignSequentialNumber();
                $botUser->refresh();
            }

            // Извлекаем новое название из текста команды
            $newName = $this->extractNewName($update->text);
            if (empty($newName)) {
                // Если название не указано, отправляем сообщение с запросом названия
                $this->sendRenameRequestMessage($botUser, $update);
                return;
            }

            // Проверяем длину названия (Telegram ограничение: до 128 символов)
            // Учитываем длину идентификатора: "#{id} " (примерно 10 символов)
            $maxNameLength = 128 - 15; // Оставляем запас для идентификатора и форматирования
            if (mb_strlen($newName) > $maxNameLength) {
                Log::warning('RenameTopic: название слишком длинное', [
                    'bot_user_id' => $botUser->id ?? null,
                    'name_length' => mb_strlen($newName),
                    'max_length' => $maxNameLength,
                ]);
                return;
            }

            // Формируем новое название с идентификатором
            $displayId = $botUser->sequential_number ?? $botUser->chat_id;
            $newTopicName = '#' . $displayId . ' ' . $newName;
            
            // Проверяем финальную длину (Telegram ограничение: до 128 символов)
            if (mb_strlen($newTopicName) > 128) {
                Log::warning('RenameTopic: итоговое название слишком длинное', [
                    'bot_user_id' => $botUser->id ?? null,
                    'topic_name_length' => mb_strlen($newTopicName),
                    'max_length' => 128,
                ]);
                return;
            }

            // Сохраняем кастомное название
            $botUser->setCustomTopicName($newTopicName);

            // Отправляем запрос на обновление названия топика
            $groupId = config('traffic_source.settings.telegram.group_id');
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'editForumTopic',
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'name' => $newTopicName,
            ]));

            Log::info('RenameTopic: название топика обновлено', [
                'bot_user_id' => $botUser->id,
                'topic_id' => $botUser->topic_id,
                'new_topic_name' => $newTopicName,
            ]);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('RenameTopic: ошибка при переименовании топика', [
                'error' => $e->getMessage(),
                'message_thread_id' => $update->messageThreadId ?? null,
            ]);
        }
    }

    /**
     * Извлекает новое название из текста команды
     *
     * @param string|null $text
     *
     * @return string
     */
    private function extractNewName(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Удаляем команду /rename_topic и опционально @bot_username
        // Поддерживаем форматы:
        // - "/rename_topic название"
        // - "/rename_topicназвание" (без пробела)
        // - "/rename_topic@GarSuppTopicsBot название"
        // - "/rename_topic@GarSuppTopicsBotназвание" (без пробела)
        $newName = preg_replace('/^\/rename_topic(?:@\w+)?\s*/i', '', $text);
        
        // Убираем лишние пробелы
        $newName = trim($newName);

        return $newName;
    }

    /**
     * Отправить сообщение с запросом названия для переименования топика
     *
     * @param BotUser $botUser
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    private function sendRenameRequestMessage(BotUser $botUser, TelegramUpdateDto $update): void
    {
        try {
            $groupId = config('traffic_source.settings.telegram.group_id');
            
            // Удаляем сообщение с командой
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'deleteMessage',
                'chat_id' => $groupId,
                'message_id' => $update->messageId,
            ]));
            
            // Отправляем сообщение с запросом названия
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'text' => __('messages.command_rename_topic_request'),
                'parse_mode' => 'html',
            ]));

            Log::info('RenameTopic: отправлен запрос на ввод названия', [
                'bot_user_id' => $botUser->id,
                'topic_id' => $botUser->topic_id,
            ]);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('RenameTopic: ошибка при отправке запроса названия', [
                'error' => $e->getMessage(),
                'bot_user_id' => $botUser->id ?? null,
            ]);
        }
    }
}

