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
                // Для editForumTopic логируем более детальную информацию
                if ($methodQuery === 'editForumTopic') {
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
                \Illuminate\Support\Facades\Log::debug('Иконка топика успешно обновлена', [
                    'method' => $methodQuery,
                    'topic_id' => $params['message_thread_id'] ?? null,
                    'icon' => $params['icon_custom_emoji_id'] ?? null,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            return false;
        }
    }
}
