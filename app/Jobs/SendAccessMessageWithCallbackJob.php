<?php

namespace App\Jobs;

use App\DTOs\TGTextMessageDto;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сообщения с кнопками доступа и его закрепление в личном чате сотрудника
 */
class SendAccessMessageWithCallbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        public int $botUserId,
        public TGTextMessageDto $queryParams,
        public bool $force = false,
    ) {
    }

    public function handle(): void
    {
        try {
            $botUser = BotUser::find($this->botUserId);

            if (!$botUser || (!$this->force && $botUser->hasAccessMessage())) {
                return;
            }

            // При принудительной пересылке (например, сотрудник удалил закреплённое
            // сообщение) на всякий случай снимаем старый пин — не критично, если
            // старого сообщения уже не существует, ошибка просто игнорируется
            if ($this->force && $botUser->hasAccessMessage()) {
                TelegramMethods::sendQueryTelegram('unpinChatMessage', [
                    'chat_id' => $botUser->chat_id,
                    'message_id' => $botUser->access_message_id,
                ]);
            }

            $response = TelegramMethods::sendQueryTelegram(
                $this->queryParams->methodQuery,
                $this->queryParams->toArray(),
                $this->queryParams->token
            );

            if (!$response->ok || !isset($response->message_id)) {
                Log::warning('SendAccessMessageWithCallbackJob: ошибка отправки сообщения доступа', [
                    'bot_user_id' => $this->botUserId,
                    'error' => $response->rawData ?? 'Unknown error',
                ]);
                return;
            }

            $botUser->access_message_id = $response->message_id;
            $botUser->save();

            $pinResponse = TelegramMethods::sendQueryTelegram('pinChatMessage', [
                'chat_id' => $botUser->chat_id,
                'message_id' => $response->message_id,
                'disable_notification' => true,
            ]);

            if (!$pinResponse->ok) {
                Log::warning('SendAccessMessageWithCallbackJob: не удалось закрепить сообщение доступа', [
                    'bot_user_id' => $botUser->id,
                    'error' => $pinResponse->rawData['description'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendAccessMessageWithCallbackJob: исключение при отправке', [
                'bot_user_id' => $this->botUserId,
                'error' => $e->getMessage(),
            ]);
            (new LokiLogger())->logException($e);
        }
    }
}
