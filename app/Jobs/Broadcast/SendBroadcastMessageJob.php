<?php

namespace App\Jobs\Broadcast;

use App\Actions\Telegram\BanMessage;
use App\DTOs\TGTextMessageDto;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBroadcastMessageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60; // Увеличенный таймаут для файлов

    public int $botUserId;

    public TGTextMessageDto $queryParams;

    private mixed $telegramMethods;

    public function __construct(
        int $botUserId,
        TGTextMessageDto $queryParams,
        mixed $telegramMethods = null,
    ) {
        $this->botUserId = $botUserId;
        $this->queryParams = $queryParams;
        $this->telegramMethods = $telegramMethods ?? new TelegramMethods();
    }

    public function handle(): void
    {
        // Проверяем, не был ли batch отменен
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        try {
            $botUser = BotUser::find($this->botUserId);

            if (!$botUser) {
                Log::warning('SendBroadcastMessageJob: пользователь не найден', [
                    'bot_user_id' => $this->botUserId,
                ]);
                return;
            }

            // Обновляем chat_id из botUser (может измениться)
            $this->queryParams->chat_id = $botUser->chat_id;

            $methodQuery = $this->queryParams->methodQuery;
            $params = $this->queryParams->toArray();

            $response = $this->telegramMethods->sendQueryTelegram(
                $methodQuery,
                $params,
                $this->queryParams->token
            );

            if ($response->ok === true) {
                Log::debug('SendBroadcastMessageJob: сообщение успешно отправлено', [
                    'bot_user_id' => $this->botUserId,
                    'chat_id' => $botUser->chat_id,
                    'method' => $methodQuery,
                ]);
                return;
            }

            // Обрабатываем ошибки
            $this->handleError($response, $botUser);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('SendBroadcastMessageJob: неожиданная ошибка', [
                'bot_user_id' => $this->botUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка ошибок при отправке сообщения
     *
     * @param mixed $response
     * @param BotUser $botUser
     *
     * @return void
     */
    protected function handleError(mixed $response, BotUser $botUser): void
    {
        // ✅ 429 Too Many Requests
        if ($response->response_code === 429) {
            // Извлекаем retry_after из rawData (Telegram API возвращает его в parameters.retry_after)
            $retryAfter = $response->rawData['parameters']['retry_after'] ?? 3;
            Log::warning("SendBroadcastMessageJob: 429 Too Many Requests. Повтор через {$retryAfter} сек.", [
                'bot_user_id' => $this->botUserId,
                'chat_id' => $botUser->chat_id,
            ]);
            $this->release($retryAfter);
            return;
        }

        // ✅ 400 MARKDOWN_ERROR
        if ($response->response_code === 400 && $response->type_error === 'MARKDOWN_ERROR') {
            Log::warning('SendBroadcastMessageJob: MARKDOWN_ERROR → переключаем parse_mode в HTML', [
                'bot_user_id' => $this->botUserId,
            ]);
            $this->queryParams->parse_mode = 'html';
            $this->release(1);
            return;
        }

        // ✅ 400 - file not found или file is too big
        if ($response->response_code === 400) {
            $errorDescription = $response->description ?? '';
            if (str_contains($errorDescription, 'file not found') || 
                str_contains($errorDescription, 'file is too big') ||
                str_contains($errorDescription, 'Bad Request: file')) {
                Log::warning('SendBroadcastMessageJob: проблема с файлом', [
                    'bot_user_id' => $this->botUserId,
                    'chat_id' => $botUser->chat_id,
                    'error' => $errorDescription,
                    'type_error' => $response->type_error ?? null,
                ]);
                // Не повторяем - файл невалиден
                return;
            }
        }

        // ✅ 403 — пользователь заблокировал бота
        if ($response->response_code === 403) {
            Log::warning('SendBroadcastMessageJob: 403 — пользователь заблокировал бота', [
                'bot_user_id' => $this->botUserId,
                'chat_id' => $botUser->chat_id,
            ]);
            // Помечаем пользователя как забаненного (опционально)
            // BanMessage::execute($this->botUserId, null);
            return;
        }

        // ✅ 400 - chat not found
        if ($response->response_code === 400 && 
            ($response->type_error === 'CHAT_NOT_FOUND' || str_contains($response->description ?? '', 'chat not found'))) {
            Log::warning('SendBroadcastMessageJob: чат не найден', [
                'bot_user_id' => $this->botUserId,
                'chat_id' => $botUser->chat_id,
            ]);
            return;
        }

        // ✅ Неизвестная ошибка
        Log::error('SendBroadcastMessageJob: неизвестная ошибка', [
            'bot_user_id' => $this->botUserId,
            'chat_id' => $botUser->chat_id,
            'response_code' => $response->response_code ?? null,
            'type_error' => $response->type_error ?? null,
            'description' => $response->description ?? null,
            'response' => $response->rawData ?? null,
        ]);
    }
}

