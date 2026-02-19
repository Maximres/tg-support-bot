<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\DTOs\TelegramUpdateDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\ExternalUser;
use Illuminate\Support\Facades\Log;

class RestoreTopicName
{
    /**
     * Восстановить название топика по умолчанию
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
                Log::warning('RestoreTopicName: messageThreadId пустой', [
                    'chat_id' => $update->chatId,
                ]);
                return;
            }

            // Получаем BotUser по topic_id
            $botUser = BotUser::getByTopicId($update->messageThreadId);
            if (!$botUser) {
                Log::warning('RestoreTopicName: BotUser не найден', [
                    'message_thread_id' => $update->messageThreadId,
                ]);
                return;
            }

            // Проверяем, что topic_id существует
            if (empty($botUser->topic_id)) {
                Log::warning('RestoreTopicName: topic_id пустой', [
                    'bot_user_id' => $botUser->id ?? null,
                ]);
                return;
            }
            
            // Убеждаемся, что sequential_number присвоен (если нет, присваиваем)
            if ($botUser->sequential_number === null) {
                $botUser->assignSequentialNumber();
                $botUser->refresh();
            }

            // Очищаем кастомное название
            $botUser->clearCustomTopicName();

            // Генерируем название по умолчанию
            $defaultTopicName = $this->generateDefaultTopicName($botUser);
            
            // Проверяем, что название не пустое
            if (empty($defaultTopicName)) {
                Log::warning('RestoreTopicName: не удалось сгенерировать название по умолчанию', [
                    'bot_user_id' => $botUser->id ?? null,
                ]);
                return;
            }
            
            // Проверяем длину названия (Telegram ограничение: до 128 символов)
            if (mb_strlen($defaultTopicName) > 128) {
                Log::warning('RestoreTopicName: сгенерированное название слишком длинное', [
                    'bot_user_id' => $botUser->id ?? null,
                    'topic_name_length' => mb_strlen($defaultTopicName),
                    'max_length' => 128,
                ]);
                // Обрезаем до максимальной длины
                $defaultTopicName = mb_substr($defaultTopicName, 0, 128);
            }

            // Отправляем запрос на обновление названия топика
            $groupId = config('traffic_source.settings.telegram.group_id');
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'editForumTopic',
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'name' => $defaultTopicName,
            ]));

            Log::info('RestoreTopicName: название топика восстановлено', [
                'bot_user_id' => $botUser->id,
                'topic_id' => $botUser->topic_id,
                'default_topic_name' => $defaultTopicName,
            ]);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('RestoreTopicName: ошибка при восстановлении названия топика', [
                'error' => $e->getMessage(),
                'message_thread_id' => $update->messageThreadId ?? null,
            ]);
        }
    }

    /**
     * Генерирует название топика по умолчанию
     * Логика аналогична TopicCreateJob::generateNameTopic()
     *
     * @param BotUser $botUser
     *
     * @return string
     */
    private function generateDefaultTopicName(BotUser $botUser): string
    {
        try {
            // Используем порядковый номер, если он присвоен, иначе fallback на chat_id
            $displayId = $botUser->sequential_number ?? $botUser->chat_id;

            if ($botUser->platform === 'external_source') {
                $source = ExternalUser::getSourceById($botUser->chat_id);
                return "#{$displayId} ({$source})";
            }

            // Получаем данные пользователя
            $nameParts = $this->getPartsGenerateName($botUser->chat_id);
            if (empty($nameParts)) {
                throw new \Exception('Name parts not found');
            }

            // Формируем название: #ПорядковыйНомер Имя Фамилия +номер
            $topicName = '#' . $displayId;

            $firstName = $nameParts['first_name'] ?? '';
            $lastName = $nameParts['last_name'] ?? '';

            if (!empty($firstName) || !empty($lastName)) {
                $fullName = trim($firstName . ' ' . $lastName);
                if (!empty($fullName)) {
                    $topicName .= ' ' . $fullName;
                }
            }

            // Добавляем номер телефона, если доступен
            if (!empty($botUser->phone_number)) {
                $topicName .= ' ' . $botUser->phone_number;
            }

            return $topicName;
        } catch (\Throwable $e) {
            // Fallback на порядковый номер или chat_id
            $displayId = $botUser->sequential_number ?? $botUser->chat_id;
            return '#' . $displayId . ' (' . $botUser->platform . ')';
        }
    }

    /**
     * Получает части для генерации названия чата
     *
     * @param int $chatId
     *
     * @return array
     */
    private function getPartsGenerateName(int $chatId): array
    {
        try {
            $chatDataQuery = GetChat::execute($chatId);
            if (!$chatDataQuery->ok) {
                throw new \Exception('ChatData not found');
            }

            $chatData = $chatDataQuery->rawData['result'];
            if (empty($chatData)) {
                throw new \Exception('ChatData not found');
            }

            $neededKeys = [
                'id',
                'email',
                'first_name',
                'last_name',
                'username',
            ];
            return array_intersect_key($chatData, array_flip($neededKeys));
        } catch (\Throwable $e) {
            return [];
        }
    }
}

