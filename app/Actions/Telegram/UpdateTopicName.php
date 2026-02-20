<?php

namespace App\Actions\Telegram;

use App\Actions\Telegram\CheckTopicExists;
use App\Actions\Telegram\GetChat;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\ExternalUser;
use Illuminate\Support\Facades\Log;

/**
 * Автоматическое обновление названия топика при изменении данных пользователя
 */
class UpdateTopicName
{
    /**
     * Обновить название топика для пользователя
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        try {
            // Edge case: проверяем наличие topic_id
            if (empty($botUser->topic_id)) {
                Log::info('UpdateTopicName: topic_id пустой, топик еще не создан', [
                    'bot_user_id' => $botUser->id ?? null,
                    'chat_id' => $botUser->chat_id ?? null,
                ]);
                return;
            }

            // Edge case: если название изменено вручную, проверяем нужно ли добавить номер
            if ($botUser->hasCustomTopicName()) {
                $customTopicName = $botUser->getCustomTopicName();
                
                // Если номер уже есть в кастомном названии, не обновляем
                // Проверяем наличие номера в названии (учитываем возможные форматы: с +, без +, с пробелами)
                if (!empty($botUser->phone_number)) {
                    $phoneInName = str_contains($customTopicName, $botUser->phone_number) ||
                                   str_contains($customTopicName, '+' . $botUser->phone_number) ||
                                   str_contains($customTopicName, ltrim($botUser->phone_number, '+'));
                    
                    if ($phoneInName) {
                        Log::info('UpdateTopicName: название топика изменено вручную и номер уже присутствует, пропускаем обновление', [
                            'bot_user_id' => $botUser->id ?? null,
                            'topic_id' => $botUser->topic_id ?? null,
                            'custom_topic_name' => $customTopicName,
                            'phone_number' => $botUser->phone_number,
                        ]);
                        return;
                    }
                }
                
                // Если номер еще не добавлен к кастомному названию, добавляем его
                if (!empty($botUser->phone_number)) {
                    // Проверяем существование топика перед обновлением
                    if (!CheckTopicExists::execute((int)$botUser->topic_id)) {
                        Log::warning('UpdateTopicName: топик не существует, пропускаем добавление номера к кастомному названию', [
                            'bot_user_id' => $botUser->id ?? null,
                            'topic_id' => $botUser->topic_id,
                        ]);
                        return;
                    }
                    
                    $newTopicName = $customTopicName . ' ' . $botUser->phone_number;
                    
                    // Проверяем длину
                    if (mb_strlen($newTopicName) > 128) {
                        Log::warning('UpdateTopicName: название с добавленным номером слишком длинное', [
                            'bot_user_id' => $botUser->id ?? null,
                            'original_length' => mb_strlen($newTopicName),
                            'max_length' => 128,
                        ]);
                        // Обрезаем, но стараемся сохранить номер
                        $maxCustomLength = 128 - mb_strlen($botUser->phone_number) - 1; // -1 для пробела
                        $newTopicName = mb_substr($customTopicName, 0, max(0, $maxCustomLength)) . ' ' . $botUser->phone_number;
                    }
                    
                    // Обновляем кастомное название с добавленным номером
                    $botUser->setCustomTopicName($newTopicName);
                    
                    // Отправляем запрос на обновление названия топика через очередь
                    $groupId = config('traffic_source.settings.telegram.group_id');
                    SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                        'methodQuery' => 'editForumTopic',
                        'chat_id' => $groupId,
                        'message_thread_id' => $botUser->topic_id,
                        'name' => $newTopicName,
                    ]));
                    
                    Log::info('UpdateTopicName: номер добавлен к кастомному названию топика', [
                        'bot_user_id' => $botUser->id,
                        'topic_id' => $botUser->topic_id,
                        'old_topic_name' => $customTopicName,
                        'new_topic_name' => $newTopicName,
                    ]);
                    return;
                }
                
                // Если номера нет, пропускаем обновление
                Log::info('UpdateTopicName: название топика изменено вручную, номер не предоставлен, пропускаем обновление', [
                    'bot_user_id' => $botUser->id ?? null,
                    'topic_id' => $botUser->topic_id ?? null,
                    'custom_topic_name' => $customTopicName,
                ]);
                return;
            }

            // Edge case: проверяем существование топика перед обновлением
            if (!CheckTopicExists::execute((int)$botUser->topic_id)) {
                Log::warning('UpdateTopicName: топик не существует, пропускаем обновление', [
                    'bot_user_id' => $botUser->id ?? null,
                    'topic_id' => $botUser->topic_id,
                ]);
                return;
            }

            // Edge case: убеждаемся, что sequential_number присвоен
            if ($botUser->sequential_number === null) {
                $botUser->assignSequentialNumber();
                $botUser->refresh();
            }

            // Генерируем новое название топика
            $topicName = $this->generateTopicName($botUser);
            
            if (empty($topicName)) {
                Log::warning('UpdateTopicName: не удалось сгенерировать название топика', [
                    'bot_user_id' => $botUser->id ?? null,
                ]);
                return;
            }

            // Edge case: проверяем длину названия (Telegram ограничение: до 128 символов)
            if (mb_strlen($topicName) > 128) {
                Log::warning('UpdateTopicName: название слишком длинное, обрезаем', [
                    'bot_user_id' => $botUser->id ?? null,
                    'original_length' => mb_strlen($topicName),
                    'max_length' => 128,
                ]);
                $topicName = mb_substr($topicName, 0, 128);
            }

            // Отправляем запрос на обновление названия топика через очередь
            $groupId = config('traffic_source.settings.telegram.group_id');
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'editForumTopic',
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'name' => $topicName,
            ]));

            Log::info('UpdateTopicName: название топика обновлено', [
                'bot_user_id' => $botUser->id,
                'topic_id' => $botUser->topic_id,
                'new_topic_name' => $topicName,
            ]);
        } catch (\Throwable $e) {
            // Edge case: ошибки при обновлении топика не должны ломать основную логику
            (new LokiLogger())->logException($e);
            Log::error('UpdateTopicName: ошибка при обновлении названия топика', [
                'error' => $e->getMessage(),
                'bot_user_id' => $botUser->id ?? null,
                'topic_id' => $botUser->topic_id ?? null,
            ]);
        }
    }

    /**
     * Генерирует название топика на основе данных пользователя
     * Логика аналогична TopicCreateJob::generateNameTopic()
     *
     * @param BotUser $botUser
     *
     * @return string
     */
    private function generateTopicName(BotUser $botUser): string
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
                // Edge case: если не удалось получить данные пользователя, используем fallback
                Log::warning('UpdateTopicName: не удалось получить данные пользователя', [
                    'bot_user_id' => $botUser->id ?? null,
                    'chat_id' => $botUser->chat_id,
                ]);
                // Fallback на порядковый номер или chat_id
                return '#' . $displayId . ' (' . $botUser->platform . ')';
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
            // Edge case: fallback на порядковый номер или chat_id
            Log::warning('UpdateTopicName: ошибка при генерации названия, используем fallback', [
                'bot_user_id' => $botUser->id ?? null,
                'error' => $e->getMessage(),
            ]);
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

