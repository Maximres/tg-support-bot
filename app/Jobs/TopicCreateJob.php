<?php

namespace App\Jobs;

use App\Actions\Telegram\GetChat;
use App\Actions\Telegram\SendContactMessage;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\ExternalUser;
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
                $this->botUser->refresh();
            }

            $topicName = $this->generateNameTopic($this->botUser);

            $response = $this->telegramMethods->sendQueryTelegram('createForumTopic', [
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'name' => $topicName,
                'icon_custom_emoji_id' => __('icons.incoming'),
            ]);

            // ✅ Успешная отправка
            if ($response->ok === true) {
                $this->botUser->topic_id = $response->message_thread_id;
                $this->botUser->save();

                (new SendContactMessage())->execute($this->botUser);
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
                return $botUser->getCustomTopicName();
            }

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
