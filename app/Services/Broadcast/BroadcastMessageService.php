<?php

namespace App\Services\Broadcast;

use App\Actions\Telegram\EntitiesToHtml;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\Broadcast\SendBroadcastMessageJob;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\Broadcast;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Массовая рассылка: сообщение, написанное администратором в топике рассылки, уходит всем клиентам.
 *
 * Что важно для безопасности (одна ошибочная рассылка — это письмо всем клиентам):
 *  - одно сообщение рассылается один раз: уникальный ключ в БД не даёт повторить рассылку, даже если Telegram
 *    повторит обновление (например, после перезапуска или выкатки);
 *  - команды (текст с «/») и служебные сообщения в рассылку не попадают;
 *  - рассылку можно выключить на время работ (/broadcast_off);
 *  - всё отправленное записывается — рассылку можно удалить у клиентов или исправить.
 */
class BroadcastMessageService
{
    /** Лимит альбома в Telegram */
    private const ALBUM_LIMIT = 10;

    /**
     * Обработка сообщения из топика рассылки
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function handle(TelegramUpdateDto $update): void
    {
        try {
            $message = $update->rawData['message'] ?? [];

            // Команды в топике рассылки выполняются как команды, но никогда не рассылаются
            if (str_starts_with(trim((string)($update->text ?? '')), '/')) {
                return;
            }

            if (!BroadcastSettings::enabled()) {
                $this->reply($update, __('messages.broadcast.disabled'));
                return;
            }

            if (!empty($message['media_group_id'])) {
                $this->handleAlbumPart($update, $message);
                return;
            }

            $plan = $this->planFor($message, $update->text, $update->caption, $update->entities);
            if (!$plan) {
                Log::warning('BroadcastMessageService: неизвестный тип сообщения', [
                    'message_id' => $update->messageId,
                    'raw_data_keys' => array_keys($message),
                ]);
                return;
            }

            $this->dispatchPlan($update, [(int)$update->messageId], $plan);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('BroadcastMessageService: ошибка при обработке массовой рассылки', [
                'update_id' => $update->updateId ?? null,
                'message_id' => $update->messageId ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * План рассылки одного сообщения: основное сообщение и, при необходимости, дополнительные текстовые
     *
     * @param array       $message
     * @param string|null $text
     * @param string|null $caption
     * @param array|null  $entities
     *
     * @return array|null ['kind' => string, 'main' => TGTextMessageDto, 'follow_ups' => TGTextMessageDto[]]
     */
    public function planFor(array $message, ?string $text, ?string $caption, ?array $entities): ?array
    {
        $base = [
            'methodQuery' => 'sendMessage',
            'chat_id' => 0, // Будет установлен для каждого пользователя отдельно
            'token' => config('traffic_source.settings.telegram.token'),
        ];

        $media = $this->mediaOf($message);

        if ($media) {
            [$method, $field, $fileId, $supportsCaption] = $media;

            $params = array_merge($base, ['methodQuery' => $method, $field => $fileId]);
            $followUps = [];

            if ($supportsCaption && !empty($caption)) {
                if (EntitiesToHtml::length($caption) <= EntitiesToHtml::CAPTION_LIMIT) {
                    $params['caption'] = EntitiesToHtml::convert($caption, $entities);
                    $params['parse_mode'] = 'html';
                } else {
                    // Подпись длиннее лимита Telegram (1024): картинка уходит без подписи, текст — следом отдельным сообщением
                    $followUps = $this->textMessages($base, $caption, $entities);
                }
            }

            return ['kind' => 'media', 'main' => TGTextMessageDto::from($params), 'follow_ups' => $followUps];
        }

        if (!empty($text)) {
            $messages = $this->textMessages($base, $text, $entities);

            if (empty($messages)) {
                return null;
            }

            $main = array_shift($messages);

            return ['kind' => 'text', 'main' => $main, 'follow_ups' => $messages];
        }

        return null;
    }

    /**
     * Текст, разбитый на сообщения не длиннее лимита Telegram; ссылки и форматирование сохраняются
     *
     * @param array       $base
     * @param string      $text
     * @param array|null  $entities
     *
     * @return TGTextMessageDto[]
     */
    private function textMessages(array $base, string $text, ?array $entities): array
    {
        return array_map(
            fn (string $html) => TGTextMessageDto::from(array_merge($base, ['methodQuery' => 'sendMessage', 'text' => $html, 'parse_mode' => 'html'])),
            EntitiesToHtml::convertInChunks($text, $entities)
        );
    }

    /**
     * Вложение сообщения: [метод, поле, file_id, поддерживает ли подпись]
     *
     * @param array $message
     *
     * @return array|null
     */
    private function mediaOf(array $message): ?array
    {
        if (!empty($message['photo'])) {
            $photo = end($message['photo']); // самое большое фото

            return ['sendPhoto', 'photo', $photo['file_id'] ?? null, true];
        }

        return match (true) {
            !empty($message['document']) => ['sendDocument', 'document', $message['document']['file_id'] ?? null, true],
            !empty($message['audio']) => ['sendAudio', 'audio', $message['audio']['file_id'] ?? null, true],
            !empty($message['video']) => ['sendVideo', 'video', $message['video']['file_id'] ?? null, true],
            !empty($message['animation']) => ['sendAnimation', 'animation', $message['animation']['file_id'] ?? null, true],
            !empty($message['voice']) => ['sendVoice', 'voice', $message['voice']['file_id'] ?? null, true],
            !empty($message['video_note']) => ['sendVideoNote', 'video_note', $message['video_note']['file_id'] ?? null, false],
            !empty($message['sticker']) => ['sendSticker', 'sticker', $message['sticker']['file_id'] ?? null, false],
            default => null,
        };
    }

    /**
     * Часть альбома. Telegram присылает альбом отдельными обновлениями: первая часть становится «ведущей»,
     * ждёт остальные и отправляет альбом целиком одним сообщением
     *
     * @param TelegramUpdateDto $update
     * @param array             $message
     *
     * @return void
     */
    private function handleAlbumPart(TelegramUpdateDto $update, array $message): void
    {
        $groupId = (string)$message['media_group_id'];
        $key = 'broadcast_album_' . (int)($update->chatId ?? 0) . '_' . md5($groupId);

        // Альбом уже отправлен, а часть пришла позже — не теряем её, отправляем отдельным сообщением
        if (Cache::has($key . '_done')) {
            Log::warning('BroadcastMessageService: часть альбома пришла после отправки, отправляю отдельно', ['message_id' => $update->messageId]);
            $plan = $this->planFor($message, $update->text, $update->caption, $update->entities);
            if ($plan) {
                $this->dispatchPlan($update, [(int)$update->messageId], $plan);
            }
            return;
        }

        $lock = Cache::lock($key . '_lock', 10);
        $lock->block(5);
        try {
            $parts = Cache::get($key . '_parts', []);
            $parts[(int)$update->messageId] = [
                'message' => $message,
                'caption' => $update->caption,
                'entities' => $update->entities,
            ];
            Cache::put($key . '_parts', $parts, 300);
        } finally {
            $lock->release();
        }

        // Ведущей становится только первая часть, остальные просто добавляют себя и выходят
        if (!Cache::add($key . '_leader', true, 300)) {
            return;
        }

        $seconds = (int)config('traffic_source.settings.telegram.broadcast_album_wait', 4);
        if ($seconds > 0) {
            sleep($seconds);
        }

        $parts = Cache::get($key . '_parts', []);
        Cache::put($key . '_done', true, 3600);
        Cache::forget($key . '_parts');

        $this->dispatchAlbum($update, $parts);
    }

    /**
     * @param TelegramUpdateDto $update
     * @param array             $parts
     *
     * @return void
     */
    private function dispatchAlbum(TelegramUpdateDto $update, array $parts): void
    {
        ksort($parts);
        $items = [];
        $captionText = null;
        $captionEntities = null;

        foreach (array_slice($parts, 0, self::ALBUM_LIMIT, true) as $part) {
            $media = $this->mediaOf($part['message']);

            if (!$media || !in_array($media[0], ['sendPhoto', 'sendVideo', 'sendDocument', 'sendAudio'], true)) {
                continue;
            }

            $items[] = ['type' => ['sendPhoto' => 'photo', 'sendVideo' => 'video', 'sendDocument' => 'document', 'sendAudio' => 'audio'][$media[0]], 'media' => $media[2]];

            if ($captionText === null && !empty($part['caption'])) {
                $captionText = $part['caption'];
                $captionEntities = $part['entities'];
            }
        }

        if (empty($items)) {
            Log::warning('BroadcastMessageService: в альбоме нет поддерживаемых вложений');
            return;
        }

        $base = [
            'methodQuery' => 'sendMediaGroup',
            'chat_id' => 0,
            'token' => config('traffic_source.settings.telegram.token'),
        ];
        $followUps = [];

        if ($captionText !== null) {
            if (EntitiesToHtml::length($captionText) <= EntitiesToHtml::CAPTION_LIMIT) {
                $items[0]['caption'] = EntitiesToHtml::convert($captionText, $captionEntities);
                $items[0]['parse_mode'] = 'html';
            } else {
                $followUps = $this->textMessages(array_merge($base, ['methodQuery' => 'sendMessage']), $captionText, $captionEntities);
            }
        }

        $this->dispatchPlan($update, array_map('intval', array_keys($parts)), [
            'kind' => 'album',
            'main' => TGTextMessageDto::from($base),
            'follow_ups' => $followUps,
            'media_group' => $items,
        ]);
    }

    /**
     * Запись о рассылке (защита от повторной отправки) и постановка отправки каждому клиенту
     *
     * @param TelegramUpdateDto $update
     * @param int[]             $sourceMessageIds
     * @param array             $plan
     *
     * @return void
     */
    private function dispatchPlan(TelegramUpdateDto $update, array $sourceMessageIds, array $plan): void
    {
        $chatId = (int)($update->chatId ?? 0);
        $first = min($sourceMessageIds);

        try {
            $broadcast = Broadcast::firstOrCreate(
                ['source_chat_id' => $chatId, 'source_message_id' => $first],
                [
                    'source_message_ids' => array_values($sourceMessageIds),
                    'update_id' => $update->updateId,
                    'kind' => $plan['kind'],
                    'has_follow_up' => !empty($plan['follow_ups']),
                    'status' => Broadcast::STATUS_SENDING,
                ]
            );
        } catch (QueryException $e) {
            Log::warning('BroadcastMessageService: сообщение уже рассылается (параллельный запрос)', ['message_id' => $first]);
            return;
        }

        if (!$broadcast->wasRecentlyCreated) {
            Log::warning('BroadcastMessageService: сообщение уже было разослано, повторно не отправляю', [
                'update_id' => $update->updateId,
                'message_id' => $first,
            ]);
            return;
        }

        $users = $this->getBroadcastUsers();
        if ($users->isEmpty()) {
            Log::warning('BroadcastMessageService: нет пользователей для рассылки');
            $broadcast->update(['status' => Broadcast::STATUS_SENT]);
            return;
        }

        Log::info('BroadcastMessageService: начинаем массовую рассылку', [
            'broadcast_id' => $broadcast->id,
            'method' => $plan['main']->methodQuery,
            'kind' => $plan['kind'],
            'users_count' => $users->count(),
        ]);

        $mediaGroup = $plan['media_group'] ?? null;

        // Разбиваем пользователей на чанки по 100 и создаем batch jobs
        $users->chunk(100)->each(function ($chunk) use ($plan, $broadcast, $mediaGroup) {
            $jobs = $chunk->map(function ($user) use ($plan, $broadcast, $mediaGroup) {
                $main = clone $plan['main'];
                $main->chat_id = $user->chat_id;

                return (new SendBroadcastMessageJob($user->id, $main, $broadcast->id, $plan['follow_ups'], $mediaGroup))
                    ->onQueue('broadcast');
            });

            Bus::batch($jobs)->dispatch();
        });

        $broadcast->update(['status' => Broadcast::STATUS_SENT]);

        Log::info('BroadcastMessageService: массовая рассылка запущена', [
            'broadcast_id' => $broadcast->id,
            'users_count' => $users->count(),
        ]);
    }

    /**
     * Сообщение в топик рассылки (отчёты и предупреждения для администраторов)
     *
     * @param TelegramUpdateDto $update
     * @param string            $text
     *
     * @return void
     */
    public function reply(TelegramUpdateDto $update, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $update->messageThreadId,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }

    /**
     * Получает список пользователей для массовой рассылки
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getBroadcastUsers()
    {
        return BotUser::where('is_banned', false)
            ->whereNotNull('chat_id')
            ->where('platform', 'telegram')
            ->get();
    }
}
