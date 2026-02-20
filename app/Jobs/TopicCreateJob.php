<?php

namespace App\Jobs;

use App\Actions\Telegram\GetChat;
use App\Actions\Telegram\SendContactMessage;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\ExternalUser;
use App\Models\Message;
use App\TelegramBot\TelegramMethods;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mockery\Exception;

class TopicCreateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 180, 300];

    private BotUser $botUser;

    private TelegramMethods $telegramMethods;

    private int $botUserId;

    public function __construct(
        int $botUserId,
        TelegramMethods $telegramMethods = null,
    ) {
        $this->botUserId = $botUserId;

        $this->telegramMethods = $telegramMethods ?? new TelegramMethods();
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        try {
            $this->botUser = BotUser::find($this->botUserId); // всегда свежие данные

            // Убеждаемся, что порядковый номер присвоен
            if ($this->botUser->sequential_number === null) {
                $this->botUser->assignSequentialNumber();
                // assignSequentialNumber() уже делает refresh(), но перестраховываемся
                $this->botUser->refresh();
                
                // Проверяем еще раз после присвоения
                if ($this->botUser->sequential_number === null) {
                    Log::warning('TopicCreateJob: не удалось присвоить порядковый номер', [
                        'bot_user_id' => $this->botUserId,
                    ]);
                }
            }

            $topicName = $this->generateNameTopic($this->botUser);

            // При создании топика после регистрации, если от клиента не было сообщений,
            // иконка должна быть 'incoming' (клиент ввел данные и ожидает от нас сообщения)
            // Иконка 'outgoing' устанавливается автоматически при отправке сообщения клиенту через updateTopic
            $iconEmojiId = __('icons.incoming');

            $response = $this->telegramMethods->sendQueryTelegram('createForumTopic', [
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'name' => $topicName,
                'icon_custom_emoji_id' => $iconEmojiId,
            ]);

            // ✅ Успешная отправка
            if ($response->ok === true) {
                $this->botUser->topic_id = $response->message_thread_id;
                $this->botUser->save();

                // Отправляем контактное сообщение через UpdateContactMessage для сохранения message_id
                // Это должно быть сделано до обновления названия, чтобы иконка не менялась
                (new \App\Actions\Telegram\UpdateContactMessage())->execute($this->botUser);

                // Обновляем название топика с учетом данных регистрации (full_name, email)
                // Это особенно важно, если топик создается после завершения регистрации
                // Делаем это после отправки контактного сообщения, чтобы иконка не менялась
                if ($this->botUser->isRegistrationCompleted()) {
                    try {
                        // Проверяем, были ли сообщения от клиента перед обновлением названия
                        $hasIncomingMessages = \App\Models\Message::where('bot_user_id', $this->botUser->id)
                            ->where('message_type', 'incoming')
                            ->exists();
                        
                        // Обновляем название только если были сообщения от клиента
                        // Если сообщений не было, название уже правильное (создано при создании топика)
                        if ($hasIncomingMessages) {
                            (new \App\Actions\Telegram\UpdateTopicName())->execute($this->botUser);
                        }
                    } catch (\Throwable $e) {
                        // Edge case: ошибка обновления названия не должна прерывать создание топика
                        Log::warning('TopicCreateJob: ошибка обновления названия топика после создания', [
                            'bot_user_id' => $this->botUser->id ?? null,
                            'topic_id' => $this->botUser->topic_id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                return;
            }

            // ✅ 429 Too Many Requests
            if ($response->response_code === 429) {
                $retryAfter = $response->parameters->retry_after ?? 3;
                Log::warning("429 Too Many Requests. Повтор через {$retryAfter} сек.");
                $this->release($retryAfter);
                return;
            }

            // ✅ Неизвестная ошибка
            Log::error('TopicCreateJob: неизвестная ошибка', [
                'response' => (array)$response,
            ]);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
        }
    }

    /**
     * Генерируем название чата
     *
     * @param BotUser $botUser
     *
     * @return string
     */
    protected function generateNameTopic(BotUser $botUser): string
    {
        try {
            // Если есть кастомное название, используем его
            if ($botUser->hasCustomTopicName()) {
                Log::info('TopicCreateJob: используется кастомное название топика', [
                    'bot_user_id' => $botUser->id,
                    'custom_topic_name' => $botUser->getCustomTopicName(),
                ]);
                return $botUser->getCustomTopicName();
            }

            // Используем порядковый номер, если он присвоен, иначе fallback на chat_id
            $displayId = $botUser->sequential_number ?? $botUser->chat_id;
            
            Log::info('TopicCreateJob: генерация названия топика', [
                'bot_user_id' => $botUser->id,
                'sequential_number' => $botUser->sequential_number,
                'chat_id' => $botUser->chat_id,
                'display_id' => $displayId,
            ]);

            if ($botUser->platform === 'external_source') {
                $source = ExternalUser::getSourceById($botUser->chat_id);
                return "#{$displayId} ({$source})";
            }

            // Формируем название: #ID ФИО ТЕЛЕФОН
            $topicName = '#' . $displayId;

            // Используем full_name из БД, если доступен, иначе получаем из Telegram API
            if (!empty($botUser->full_name)) {
                $topicName .= ' ' . $botUser->full_name;
            } else {
                // Fallback на данные из Telegram API
                $nameParts = $this->getPartsGenerateName($botUser->chat_id);
                if (!empty($nameParts)) {
                    $firstName = $nameParts['first_name'] ?? '';
                    $lastName = $nameParts['last_name'] ?? '';
                    
                    if (!empty($firstName) || !empty($lastName)) {
                        $fullName = trim($firstName . ' ' . $lastName);
                        if (!empty($fullName)) {
                            $topicName .= ' ' . $fullName;
                        }
                    }
                }
            }

            // Добавляем номер телефона, если доступен
            if (!empty($botUser->phone_number)) {
                $topicName .= ' ' . $botUser->phone_number;
            }

            // Email не добавляем в название топика, он отображается в контактной информации

            return $topicName;
        } catch (\Throwable $e) {
            // Fallback на порядковый номер или chat_id
            $displayId = $botUser->sequential_number ?? $botUser->chat_id;
            return '#' . $displayId . ' (' . $botUser->platform . ')';
        }
    }

    /**
     * Получаем части для генерации названия чата
     *
     * @param int $chatId
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getPartsGenerateName(int $chatId): array
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
        } catch (Exception $e) {
            return [];
        }
    }
}
