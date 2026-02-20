<?php

namespace App\Jobs;

use App\DTOs\TGTextMessageDto;
use App\Logging\LokiLogger;
use App\TelegramBot\TelegramMethods;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramSimpleQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    public TGTextMessageDto $queryParams;

    public function __construct(
        TGTextMessageDto $queryParams,
    ) {
        $this->queryParams = $queryParams;
    }

    public function handle(): mixed
    {
        try {
            $methodQuery = $this->queryParams->methodQuery;
            $params = $this->queryParams->toArray();

            $response = TelegramMethods::sendQueryTelegram(
                $methodQuery,
                $params,
                $this->queryParams->token
            );

            if (!$response->ok) {
                // Для editForumTopic обрабатываем специальные случаи
                if ($methodQuery === 'editForumTopic') {
                    // TOPIC_NOT_MODIFIED - это не ошибка, топик просто не изменился
                    // (например, иконка уже null или та же самая)
                    $errorDescription = $response->description ?? '';
                    if (str_contains($errorDescription, 'TOPIC_NOT_MODIFIED')) {
                        \Illuminate\Support\Facades\Log::debug('Топик не был изменен (TOPIC_NOT_MODIFIED)', [
                            'method' => $methodQuery,
                            'topic_id' => $params['message_thread_id'] ?? null,
                            'icon' => $params['icon_custom_emoji_id'] ?? null,
                            'name' => $params['name'] ?? null,
                        ]);
                        
                        // Освобождаем блокировку, если она была установлена
                        $topicId = $params['message_thread_id'] ?? null;
                        if ($topicId) {
                            $lockKey = 'topic_icon_update_' . $topicId;
                            \Illuminate\Support\Facades\Cache::forget($lockKey);
                            \Illuminate\Support\Facades\Cache::forget($lockKey . '_released');
                        }
                        
                        // Возвращаем успех, так как это не ошибка
                        return true;
                    }
                    
                    // Для других ошибок логируем предупреждение
                    \Illuminate\Support\Facades\Log::warning('Ошибка обновления иконки топика', [
                        'method' => $methodQuery,
                        'topic_id' => $params['message_thread_id'] ?? null,
                        'icon' => $params['icon_custom_emoji_id'] ?? null,
                        'response_code' => $response->response_code ?? null,
                        'error' => $response->type_error ?? null,
                        'description' => $response->description ?? null,
                        'raw_response' => $response->rawData ?? null,
                    ]);
                }
                
                throw new \Exception(json_encode($response->rawData), 1);
            }

            // Логируем успешное обновление иконки для отладки
            if ($methodQuery === 'editForumTopic') {
                $topicId = $params['message_thread_id'] ?? null;
                \Illuminate\Support\Facades\Log::info('Иконка топика успешно обновлена', [
                    'method' => $methodQuery,
                    'topic_id' => $topicId,
                    'icon' => $params['icon_custom_emoji_id'] ?? null,
                    'timestamp' => now()->toIso8601String(),
                ]);
                
                // Освобождаем блокировку после успешного обновления
                if ($topicId) {
                    $lockKey = 'topic_icon_update_' . $topicId;
                    \Illuminate\Support\Facades\Cache::forget($lockKey);
                    \Illuminate\Support\Facades\Cache::forget($lockKey . '_released');
                }
            }

            return true;
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            return false;
        }
    }
}
