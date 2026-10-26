<?php

namespace App\Jobs\Broadcast;

use App\DTOs\TGTextMessageDto;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Enums\TelegramError;
use App\Models\BroadcastMessage;
use App\TelegramBot\ParserMethods;
use App\TelegramBot\TelegramMethods;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Отправка одного клиенту сообщения массовой рассылки (и, если есть, дополнительных текстовых сообщений:
 * длинная подпись к картинке уходит отдельным текстом) с записью id отправленных сообщений.
 * Записанные id нужны, чтобы потом удалить или исправить рассылку у клиентов.
 */
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

    /**
     * @param int                  $botUserId
     * @param TGTextMessageDto     $queryParams
     * @param int|null             $broadcastId Рассылка, к которой относится сообщение (для учёта отправленного)
     * @param TGTextMessageDto[]   $followUps   Дополнительные текстовые сообщения, отправляемые следом
     * @param array|null           $mediaGroup  Состав альбома для sendMediaGroup (InputMedia)
     * @param mixed                $telegramMethods
     */
    public function __construct(
        int $botUserId,
        TGTextMessageDto $queryParams,
        public ?int $broadcastId = null,
        public array $followUps = [],
        public ?array $mediaGroup = null,
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

            $response = $this->send($this->queryParams, $this->paramsFor($this->queryParams, $botUser->chat_id));

            if ($response->ok !== true) {
                $this->handleError($response, $botUser);
                return;
            }

            $messageIds = $this->messageIds($response);

            Log::debug('SendBroadcastMessageJob: сообщение успешно отправлено', [
                'bot_user_id' => $this->botUserId,
                'chat_id' => $botUser->chat_id,
                'method' => $this->queryParams->methodQuery,
            ]);

            foreach ($this->followUps as $followUp) {
                $followUp->chat_id = $botUser->chat_id;
                $followResponse = $this->send($followUp, $followUp->toArray());

                if ($followResponse->ok === true) {
                    $messageIds = array_merge($messageIds, $this->messageIds($followResponse));
                }
            }

            $this->remember($botUser, $messageIds);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('SendBroadcastMessageJob: неожиданная ошибка', [
                'bot_user_id' => $this->botUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Параметры запроса: для альбома — состав из file_id, для остальных — поля DTO
     *
     * @param TGTextMessageDto $dto
     * @param int              $chatId
     *
     * @return array
     */
    private function paramsFor(TGTextMessageDto $dto, int $chatId): array
    {
        if ($dto->methodQuery === 'sendMediaGroup') {
            return ['chat_id' => $chatId, 'media' => $this->mediaGroup ?? []];
        }

        return $dto->toArray();
    }

    /**
     * Отправка с одной попыткой после лимита запросов (очередь синхронная — release() ничего не повторит)
     * и повтором простым текстом, если Telegram не смог разобрать разметку
     *
     * @param TGTextMessageDto $dto
     * @param array            $params
     *
     * @return mixed
     */
    private function send(TGTextMessageDto $dto, array $params): mixed
    {
        $response = $this->request($dto, $params);

        if ($response->ok !== true && $response->response_code === 429) {
            $retryAfter = (int)($response->rawData['parameters']['retry_after'] ?? 3);
            Log::warning("SendBroadcastMessageJob: 429 Too Many Requests, жду {$retryAfter} сек.", ['bot_user_id' => $this->botUserId]);
            sleep(min(max($retryAfter, 1), 10));

            $response = $this->request($dto, $params);
        }

        if ($response->ok !== true && $response->response_code === 400 && $response->type_error === 'MARKDOWN_ERROR') {
            Log::warning('SendBroadcastMessageJob: не удалось разобрать разметку, отправляю простым текстом', [
                'bot_user_id' => $this->botUserId,
            ]);

            $response = $this->request($dto, $this->withoutFormatting($params));
        }

        return $response;
    }

    /**
     * Запрос к Telegram. Ответ sendMediaGroup — список сообщений, а не одно, поэтому общий разбор ответа ему не подходит:
     * его отправляем напрямую и приводим ответ к тому же виду
     *
     * @param TGTextMessageDto $dto
     * @param array            $params
     *
     * @return object
     */
    private function request(TGTextMessageDto $dto, array $params): object
    {
        if ($dto->methodQuery !== 'sendMediaGroup') {
            return $this->telegramMethods->sendQueryTelegram($dto->methodQuery, $params, $dto->token);
        }

        $token = $dto->token ?: config('traffic_source.settings.telegram.token');
        $raw = ParserMethods::postQuery("https://api.telegram.org/bot{$token}/sendMediaGroup", $params);
        $description = (string)($raw['description'] ?? '');

        return (object)[
            'ok' => ($raw['ok'] ?? false) === true,
            'response_code' => (int)($raw['error_code'] ?? $raw['response_code'] ?? 200),
            'type_error' => TelegramError::fromResponse($description)?->name,
            'message_id' => null,
            'rawData' => $raw,
        ];
    }

    /**
     * @param array $params
     *
     * @return array
     */
    private function withoutFormatting(array $params): array
    {
        unset($params['parse_mode']);

        foreach (['text', 'caption'] as $field) {
            if (isset($params[$field]) && is_string($params[$field])) {
                $params[$field] = html_entity_decode(strip_tags($params[$field]), ENT_QUOTES);
            }
        }

        if (!empty($params['media']) && is_array($params['media'])) {
            foreach ($params['media'] as $i => $item) {
                unset($params['media'][$i]['parse_mode']);

                if (isset($item['caption'])) {
                    $params['media'][$i]['caption'] = html_entity_decode(strip_tags((string)$item['caption']), ENT_QUOTES);
                }
            }
        }

        return $params;
    }

    /**
     * Id отправленных сообщений: один для обычных методов, несколько для sendMediaGroup
     *
     * @param mixed $response
     *
     * @return int[]
     */
    private function messageIds(mixed $response): array
    {
        $result = $response->rawData['result'] ?? null;

        if (is_array($result) && array_is_list($result)) {
            return array_values(array_filter(array_map(fn ($message) => $message['message_id'] ?? null, $result)));
        }

        return $response->message_id ? [(int)$response->message_id] : [];
    }

    /**
     * @param BotUser $botUser
     * @param int[]   $messageIds
     *
     * @return void
     */
    private function remember(BotUser $botUser, array $messageIds): void
    {
        if (!$this->broadcastId || empty($messageIds)) {
            return;
        }

        BroadcastMessage::create([
            'broadcast_id' => $this->broadcastId,
            'bot_user_id' => $botUser->id,
            'chat_id' => $botUser->chat_id,
            'message_ids' => $messageIds,
        ]);
    }

    /**
     * Обработка ошибок при отправке сообщения
     *
     * @param mixed   $response
     * @param BotUser $botUser
     *
     * @return void
     */
    protected function handleError(mixed $response, BotUser $botUser): void
    {
        $description = (string)($response->rawData['description'] ?? '');

        // ✅ 400 - file not found или file is too big
        if ($response->response_code === 400
            && (str_contains($description, 'file not found') || str_contains($description, 'file is too big') || str_contains($description, 'Bad Request: file'))) {
            Log::warning('SendBroadcastMessageJob: проблема с файлом', [
                'bot_user_id' => $this->botUserId,
                'chat_id' => $botUser->chat_id,
                'error' => $description,
                'type_error' => $response->type_error ?? null,
            ]);
            // Не повторяем - файл невалиден
            return;
        }

        // ✅ 403 — пользователь заблокировал бота
        if ($response->response_code === 403) {
            Log::warning('SendBroadcastMessageJob: 403 — пользователь заблокировал бота', [
                'bot_user_id' => $this->botUserId,
                'chat_id' => $botUser->chat_id,
            ]);
            return;
        }

        // ✅ 400 - chat not found (клиент не запускал бота)
        if ($response->response_code === 400
            && ($response->type_error === 'CHAT_NOT_FOUND' || str_contains($description, 'chat not found'))) {
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
            'description' => $description,
            'response' => $response->rawData ?? null,
        ]);
    }
}
