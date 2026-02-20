<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\Message;
use App\TelegramBot\TelegramMethods;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Отправка контактного сообщения с сохранением message_id
 */
class SendContactMessageWithCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 20;

    public function __construct(
        public int $botUserId,
        public TGTextMessageDto $queryParams,
    ) {}

    public function handle(): void
    {
        try {
            $botUser = BotUser::find($this->botUserId);
            
            if (!$botUser) {
                Log::warning('SendContactMessageWithCallbackJob: пользователь не найден', [
                    'bot_user_id' => $this->botUserId,
                ]);
                return;
            }

            $params = $this->queryParams->toArray();
            $response = TelegramMethods::sendQueryTelegram(
                $this->queryParams->methodQuery,
                $params,
                $this->queryParams->token
            );

            if ($response->ok && isset($response->message_id)) {
                // Сохраняем message_id в BotUser
                $botUser->contact_info_message_id = $response->message_id;
                $botUser->save();

                // Если от клиента не было сообщений, устанавливаем иконку 'incoming'
                // (клиент ввел данные и ожидает от нас сообщения)
                $hasIncomingMessages = Message::where('bot_user_id', $botUser->id)
                    ->where('message_type', 'incoming')
                    ->exists();

                if (!$hasIncomingMessages && !empty($botUser->topic_id)) {
                    $iconIncoming = __('icons.incoming');
                    if (!empty($iconIncoming)) {
                        // Устанавливаем иконку 'incoming' с небольшой задержкой
                        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                            'methodQuery' => 'editForumTopic',
                            'chat_id' => config('traffic_source.settings.telegram.group_id'),
                            'message_thread_id' => $botUser->topic_id,
                            'icon_custom_emoji_id' => $iconIncoming,
                        ]))->delay(now()->addSeconds(1));
                        
                        Log::info('SendContactMessageWithCallbackJob: установлена иконка incoming после отправки контактного сообщения', [
                            'bot_user_id' => $botUser->id,
                            'topic_id' => $botUser->topic_id,
                        ]);
                    }
                }

                Log::info('SendContactMessageWithCallbackJob: контактное сообщение отправлено, message_id сохранен', [
                    'bot_user_id' => $botUser->id,
                    'message_id' => $response->message_id,
                ]);
            } else {
                Log::warning('SendContactMessageWithCallbackJob: ошибка отправки контактного сообщения', [
                    'bot_user_id' => $botUser->id,
                    'error' => $response->rawData ?? 'Unknown error',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendContactMessageWithCallbackJob: исключение при отправке', [
                'bot_user_id' => $this->botUserId,
                'error' => $e->getMessage(),
            ]);
            (new LokiLogger())->logException($e);
        }
    }
}

