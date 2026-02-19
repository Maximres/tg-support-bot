<?php

namespace App\Services\Broadcast;

use App\DTOs\TGTextMessageDto;
use App\DTOs\TelegramUpdateDto;
use App\Jobs\Broadcast\SendBroadcastMessageJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BroadcastMessageService
{
    /**
     * Обработка массовой рассылки сообщения
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function handle(TelegramUpdateDto $update): void
    {
        try {
            // Проверка на дублирование (idempotency)
            $messageId = $update->messageId ?? 0; // Обрабатываем случай, когда messageId может быть null
            $cacheKey = "broadcast_processed_{$update->updateId}_{$messageId}";
            if (Cache::has($cacheKey)) {
                Log::debug('BroadcastMessageService: сообщение уже обработано', [
                    'update_id' => $update->updateId,
                    'message_id' => $messageId,
                ]);
                return;
            }

            // Определяем тип сообщения и создаем DTO
            $dto = $this->createMessageDto($update);
            if (!$dto) {
                Log::warning('BroadcastMessageService: не удалось создать DTO для сообщения', [
                    'update_id' => $update->updateId,
                    'message_id' => $update->messageId,
                ]);
                return;
            }

            // Получаем список пользователей для рассылки
            $users = $this->getBroadcastUsers();
            if ($users->isEmpty()) {
                Log::warning('BroadcastMessageService: нет пользователей для рассылки');
                return;
            }

            Log::info('BroadcastMessageService: начинаем массовую рассылку', [
                'update_id' => $update->updateId,
                'message_id' => $update->messageId,
                'method' => $dto->methodQuery,
                'users_count' => $users->count(),
            ]);

            // Разбиваем пользователей на чанки по 100 и создаем batch jobs
            $users->chunk(100)->each(function ($chunk) use ($dto) {
                $jobs = $chunk->map(function ($user) use ($dto) {
                    // Создаем новый DTO для каждого пользователя с его chat_id
                    // Используем все свойства DTO напрямую для создания нового экземпляра
                    $dtoData = [
                        'methodQuery' => $dto->methodQuery,
                        'token' => $dto->token,
                        'typeSource' => $dto->typeSource,
                        'chat_id' => $user->chat_id,
                        'message_id' => $dto->message_id,
                        'message_thread_id' => $dto->message_thread_id,
                        'text' => $dto->text,
                        'caption' => $dto->caption,
                        'parse_mode' => $dto->parse_mode,
                        'reply_markup' => $dto->reply_markup,
                        // reply_parameters не передаем - игнорируем reply_to_message в массовой рассылке
                        'contact' => $dto->contact,
                        'file_id' => $dto->file_id,
                        'photo' => $dto->photo,
                        'document' => $dto->document,
                        'uploaded_file' => $dto->uploaded_file,
                        'uploaded_file_path' => $dto->uploaded_file_path,
                        'voice' => $dto->voice,
                        'audio' => $dto->audio,
                        'video' => $dto->video,
                        'sticker' => $dto->sticker,
                        'video_note' => $dto->video_note,
                        'media' => $dto->media,
                        'latitude' => $dto->latitude,
                        'longitude' => $dto->longitude,
                        'icon_custom_emoji_id' => $dto->icon_custom_emoji_id,
                        'name' => $dto->name,
                    ];
                    
                    $userDto = TGTextMessageDto::from($dtoData);
                    
                    return (new SendBroadcastMessageJob($user->id, $userDto))
                        ->onQueue('broadcast');
                });

                // Создаем batch job
                Bus::batch($jobs)->dispatch();
            });

            // Помечаем сообщение как обработанное (храним 1 час)
            Cache::put($cacheKey, true, 3600);

            Log::info('BroadcastMessageService: массовая рассылка запущена', [
                'update_id' => $update->updateId,
                'message_id' => $messageId,
                'users_count' => $users->count(),
                'chunks' => ceil($users->count() / 100),
            ]);
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
     * Создает DTO для отправки сообщения на основе типа сообщения
     *
     * @param TelegramUpdateDto $update
     *
     * @return TGTextMessageDto|null
     */
    protected function createMessageDto(TelegramUpdateDto $update): ?TGTextMessageDto
    {
        $rawData = $update->rawData ?? [];
        $message = $rawData['message'] ?? [];

        // Обработка пересылок: извлекаем оригинальное сообщение
        // При пересылке оригинальный контент (текст, файлы) находится в том же объекте message
        // Просто игнорируем информацию о пересылке (forward_from, forward_from_chat)
        // и используем контент сообщения напрямую
        if (!empty($message['forward_from']) || !empty($message['forward_from_chat'])) {
            Log::debug('BroadcastMessageService: обнаружена пересылка, используем контент сообщения', [
                'message_id' => $update->messageId,
            ]);
            // Контент (текст, фото, документ и т.д.) уже в $message, просто продолжаем
        }

        // Игнорируем reply_to_message - не передаем reply_parameters
        // Определяем тип сообщения
        $methodQuery = 'sendMessage';
        $params = [
            'methodQuery' => $methodQuery,
            'chat_id' => 0, // Будет установлен для каждого пользователя отдельно
            'token' => config('traffic_source.settings.telegram.token'),
        ];

        // Проверяем наличие медиа группы (временное решение: отправляем только первое сообщение)
        if (!empty($message['media_group_id'])) {
            Log::warning('BroadcastMessageService: обнаружена медиа группа, отправляем только первое сообщение', [
                'media_group_id' => $message['media_group_id'],
                'message_id' => $update->messageId,
            ]);
            // Продолжаем обработку как обычное сообщение
        }

        // Определяем тип сообщения по приоритету
        if (!empty($message['photo'])) {
            $methodQuery = 'sendPhoto';
            // Берем последнее (самое большое) фото
            $photo = end($message['photo']);
            $params['photo'] = $photo['file_id'] ?? null;
            $params['caption'] = $this->prepareCaption($update->caption);
        } elseif (!empty($message['document'])) {
            $methodQuery = 'sendDocument';
            $params['document'] = $message['document']['file_id'] ?? null;
            $params['caption'] = $this->prepareCaption($update->caption);
        } elseif (!empty($message['audio'])) {
            $methodQuery = 'sendAudio';
            $params['audio'] = $message['audio']['file_id'] ?? null;
            $params['caption'] = $this->prepareCaption($update->caption);
        } elseif (!empty($message['video'])) {
            $methodQuery = 'sendVideo';
            $params['video'] = $message['video']['file_id'] ?? null;
            $params['caption'] = $this->prepareCaption($update->caption);
        } elseif (!empty($message['voice'])) {
            $methodQuery = 'sendVoice';
            $params['voice'] = $message['voice']['file_id'] ?? null;
            $params['caption'] = $this->prepareCaption($update->caption);
        } elseif (!empty($message['video_note'])) {
            $methodQuery = 'sendVideoNote';
            $params['video_note'] = $message['video_note']['file_id'] ?? null;
        } elseif (!empty($update->text)) {
            $methodQuery = 'sendMessage';
            $params['text'] = $update->text;
            // Обрабатываем entities для parse_mode
            if (!empty($update->entities)) {
                $params['parse_mode'] = 'HTML'; // Используем HTML по умолчанию для безопасности
            }
        } else {
            Log::warning('BroadcastMessageService: неизвестный тип сообщения', [
                'message_id' => $update->messageId,
                'raw_data_keys' => array_keys($message),
            ]);
            return null;
        }

        $params['methodQuery'] = $methodQuery;

        return TGTextMessageDto::from($params);
    }

    /**
     * Подготавливает caption (обрезает до 1024 символов)
     *
     * @param string|null $caption
     *
     * @return string|null
     */
    protected function prepareCaption(?string $caption): ?string
    {
        if (empty($caption)) {
            return null;
        }

        // Обрезаем до 1024 символов (лимит Telegram)
        if (mb_strlen($caption) > 1024) {
            return mb_substr($caption, 0, 1024);
        }

        return $caption;
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

