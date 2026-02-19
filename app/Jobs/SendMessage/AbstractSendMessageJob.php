<?php

namespace App\Jobs\SendMessage;

use App\Actions\Telegram\BanMessage;
use App\DTOs\External\ExternalMessageDto;
use App\DTOs\TelegramAnswerDto;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\DTOs\Vk\VkUpdateDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Jobs\TopicCreateJob;
use App\Models\BotUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class AbstractSendMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    public int $botUserId;

    public mixed $updateDto;

    public mixed $queryParams;

    public string $typeMessage = '';

    abstract public function handle(): void;

    /**
     * Сохраняем сообщение в базу после успешной отправки
     *
     * @param BotUser $botUser
     * @param mixed   $resultQuery
     */
    abstract protected function saveMessage(BotUser $botUser, mixed $resultQuery): void;

    /**
     * Сохраняем сообщение в базу после успешной отправки
     *
     * @param mixed   $resultQuery
     * @param BotUser $botUser
     */
    abstract protected function editMessage(BotUser $botUser, mixed $resultQuery): void;

    /**
     * Обновляем тему в зависимости от типа источника
     *
     * @return void
     */
    protected function updateTopic(BotUser $botUser, string $typeMessage): void
    {
        // Edge case: проверяем, что topic_id не null
        if (empty($botUser->topic_id)) {
            Log::warning('updateTopic: topic_id пустой, пропускаем обновление иконки', [
                'bot_user_id' => $botUser->id ?? null,
                'type_message' => $typeMessage,
            ]);
            return;
        }

        // Если название было изменено вручную, обновляем только иконку
        $targetIcon = __('icons.' . $typeMessage);
        
        // Edge case: проверяем, что иконка не пустая
        if (empty($targetIcon)) {
            Log::warning('updateTopic: иконка пустая, пропускаем обновление', [
                'bot_user_id' => $botUser->id ?? null,
                'type_message' => $typeMessage,
                'topic_id' => $botUser->topic_id,
            ]);
            return;
        }
        
        // Защита от дублирующих обновлений: используем Redis блокировку
        // Ключ блокировки: topic_icon_update_{topic_id}
        $lockKey = 'topic_icon_update_' . $botUser->topic_id;
        $lockTimeout = 5; // секунды
        
        // Пытаемся получить блокировку
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, $lockTimeout);
        
        if (!$lock->get()) {
            // Блокировка уже установлена другим процессом - пропускаем обновление
            Log::debug('updateTopic: пропускаем обновление, так как уже есть активное обновление', [
                'bot_user_id' => $botUser->id ?? null,
                'topic_id' => $botUser->topic_id,
                'type_message' => $typeMessage,
                'target_icon' => $targetIcon,
            ]);
            return;
        }
        
        try {
            // Добавляем небольшую задержку перед обновлением иконки для предотвращения race conditions
            // Это помогает избежать конфликтов при параллельных обновлениях
            $params = [
                'methodQuery' => 'editForumTopic',
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'message_thread_id' => $botUser->topic_id,
                'icon_custom_emoji_id' => $targetIcon,
            ];

            // Не обновляем название, если оно было изменено вручную
            if (!$botUser->hasCustomTopicName()) {
                // Можно добавить обновление названия здесь, если нужно
                // Но по умолчанию обновляем только иконку
            }

            // Добавляем задержку перед отправкой для предотвращения конфликтов
            // Используем delay() для отложенной отправки
            // После выполнения джоба блокировка автоматически снимется
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from($params))
                ->delay(now()->addSeconds(1));
            
            Log::debug('updateTopic: запланировано обновление иконки', [
                'bot_user_id' => $botUser->id ?? null,
                'topic_id' => $botUser->topic_id,
                'type_message' => $typeMessage,
                'target_icon' => $targetIcon,
            ]);
        } finally {
            // Освобождаем блокировку через небольшую задержку, чтобы дать время джобу выполниться
            // Но не сразу, чтобы предотвратить дублирующие обновления
            \Illuminate\Support\Facades\Cache::put($lockKey . '_released', true, $lockTimeout);
        }
    }

    protected function telegramResponseHandler(TelegramAnswerDto $response): void
    {
        // ✅ 429 Too Many Requests
        if ($response->response_code === 429) {
            $retryAfter = $response->parameters->retry_after ?? 3;
            Log::warning("429 Too Many Requests. Повтор через {$retryAfter} сек.");
            $this->release($retryAfter);
            return;
        }

        // ✅ 400 MARKDOWN_ERROR
        if ($response->response_code === 400 && $response->type_error === 'MARKDOWN_ERROR') {
            Log::warning('MARKDOWN_ERROR → переключаем parse_mode в HTML');
            $this->queryParams->parse_mode = 'html';
            $this->release(1);
            return;
        }

        // ✅ 400 TOPIC_NOT_FOUND или TOPIC_DELETED
        if ($response->response_code === 400 && 
            ($response->type_error === 'TOPIC_NOT_FOUND' || $response->type_error === 'TOPIC_DELETED')) {
            Log::warning('TOPIC_NOT_FOUND/TOPIC_DELETED → очищаем topic_id и создаём новую тему', [
                'bot_user_id' => $this->botUserId,
                'old_topic_id' => BotUser::find($this->botUserId)->topic_id ?? null,
            ]);

            // Очищаем topic_id в БД перед созданием нового топика
            $botUser = BotUser::find($this->botUserId);
            if ($botUser && $botUser->topic_id) {
                $botUser->topic_id = null;
                $botUser->save();
            }

            if ($this->updateDto instanceof ExternalMessageDto) {
                TopicCreateJob::withChain([
                    new SendExternalTelegramMessageJob(
                        $this->botUserId,
                        $this->updateDto,
                        $this->queryParams,
                        $this->typeMessage
                    ),
                ])->dispatch($this->botUserId);
            } elseif ($this->updateDto instanceof TelegramUpdateDto) {
                TopicCreateJob::withChain([
                    new SendTelegramMessageJob(
                        $this->botUserId,
                        $this->updateDto,
                        $this->queryParams,
                        $this->typeMessage
                    ),
                ])->dispatch($this->botUserId);
            } elseif ($this->updateDto instanceof VkUpdateDto) {
                TopicCreateJob::withChain([
                    new SendVkTelegramMessageJob(
                        $this->botUserId,
                        $this->updateDto,
                        $this->queryParams,
                    ),
                ])->dispatch($this->botUserId);
            }

            return;
        }

        // ✅ 403 — пользователь заблокировал бота
        if ($response->response_code === 403) {
            Log::warning('403 — пользователь заблокировал бота');
            BanMessage::execute($this->botUserId, $this->updateDto);
            return;
        }

        // ✅ Неизвестная ошибка
        Log::error('Неизвестная ошибка', [
            'response' => (array)$response,
        ]);
    }
}
