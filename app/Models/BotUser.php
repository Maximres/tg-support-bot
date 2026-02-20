<?php

namespace App\Models;

use App\DTOs\External\ExternalMessageDto;
use App\DTOs\TelegramUpdateDto;
use App\Logging\LokiLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Exception;

/**
 * @property int               $id
 * @property int|null          $sequential_number
 * @property int               $topic_id
 * @property int|null          $contact_info_message_id
 * @property int               $chat_id
 * @property string            $platform
 * @property string|null       $phone_number
 * @property string|null       $full_name
 * @property string|null       $email
 * @property \Carbon\Carbon|null $registration_completed_at
 * @property string|null       $custom_topic_name
 * @property bool              $topic_name_edited
 * @property mixed             $aiCondition
 * @property mixed             $lastMessageManager
 * @property ExternalUser|null $externalUser
 * @property bool              $is_banned
 */
class BotUser extends Model
{
    use HasFactory;

    protected $table = 'bot_users';

    protected $fillable = [
        'chat_id',
        'topic_id',
        'contact_info_message_id',
        'platform',
        'phone_number',
        'full_name',
        'email',
        'registration_completed_at',
        'custom_topic_name',
        'topic_name_edited',
        'sequential_number',
        'is_banned',
        'banned_at',
    ];

    /**
     * @return HasOne
     */
    public function externalUser(): HasOne
    {
        return $this->hasOne(ExternalUser::class, 'id', 'chat_id');
    }

    /**
     * @return HasOne
     */
    public function aiCondition(): HasOne
    {
        return $this->hasOne(AiCondition::class);
    }

    /**
     * @return HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'id', 'bot_user_id');
    }

    /**
     * @return HasOne
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * @return HasOne
     */
    public function lastMessageManager(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(['created_at' => 'max'], function ($q) {
            $q->where('message_type', 'outgoing');
        });
    }

    /**
     * Get platform by chat id
     *
     * @param int $chatId
     *
     * @return string|null
     */
    public static function getPlatformByChatId(int $chatId): ?string
    {
        try {
            $botUser = self::select('platform')
                ->where('chat_id', $chatId)
                ->first();

            return $botUser ? $botUser->platform : null;
        } catch (\Throwable $e) {
            (new LokiLogger())->sendBasicLog($e);
            return null;
        }
    }

    /**
     * Get platform by topic id
     *
     * @param int $messageThreadId
     *
     * @return string|null
     */
    public static function getPlatformByTopicId(int $messageThreadId): ?string
    {
        try {
            $botUser = self::select('platform')
                ->where('topic_id', $messageThreadId)
                ->first();

            return $botUser->platform ?? null;
        } catch (\Throwable $e) {
            (new LokiLogger())->sendBasicLog($e);
            return null;
        }
    }

    /**
     * Geg user data
     *
     * @param TelegramUpdateDto $update
     *
     * @return BotUser|null
     */
    public static function getOrCreateByTelegramUpdate(TelegramUpdateDto $update): ?BotUser
    {
        try {
            if ($update->typeSource === 'supergroup' && !empty($update->messageThreadId)) {
                $botUser = self::where('topic_id', $update->messageThreadId)
                    ->with('externalUser')
                    ->first();
            } elseif ($update->typeSource === 'private') {
                $botUser = self::firstOrCreate(
                    [
                        'chat_id' => $update->chatId,
                    ],
                    [
                        'platform' => 'telegram',
                    ]
                );
                
                // Присваиваем порядковый номер, если он еще не присвоен
                // Проверяем sequential_number вместо wasRecentlyCreated для надежности
                if ($botUser->sequential_number === null) {
                    $botUser->assignSequentialNumber();
                }
            }

            return $botUser ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param int $messageThreadId
     *
     * @return BotUser|null
     */
    public static function getByTopicId(int $messageThreadId): ?BotUser
    {
        try {
            return self::where('topic_id', $messageThreadId)
                ->with('externalUser')
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string|int $chatId
     * @param string     $platform
     *
     * @return BotUser|null
     */
    public static function getUserByChatId(string|int $chatId, string $platform): ?BotUser
    {
        try {
            $botUser = self::firstOrCreate([
                'chat_id' => $chatId,
            ], [
                'platform' => $platform,
            ]);
            
            // Присваиваем порядковый номер, если он еще не присвоен
            // Проверяем sequential_number вместо wasRecentlyCreated для надежности
            if ($botUser->sequential_number === null) {
                $botUser->assignSequentialNumber();
            }
            
            return $botUser;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param ExternalMessageDto $updateData
     *
     * @return BotUser|null
     */
    public function getOrCreateExternalBotUser(ExternalMessageDto $updateData): ?BotUser
    {
        try {
            $this->externalUser = ExternalUser::firstOrCreate([
                'external_id' => $updateData->external_id,
                'source' => $updateData->source,
            ]);

            if (empty($this->externalUser)) {
                throw new Exception('External user not found!');
            }

            $botUser = BotUser::firstOrCreate([
                'chat_id' => $this->externalUser->id,
                'platform' => $this->externalUser->source,
            ]);
            
            // Присваиваем порядковый номер, если он еще не присвоен
            // Проверяем sequential_number вместо wasRecentlyCreated для надежности
            if ($botUser->sequential_number === null) {
                $botUser->assignSequentialNumber();
            }
            
            return $botUser;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string $external_id
     * @param string $source
     *
     * @return BotUser|null
     */
    public function getExternalBotUser(string $external_id, string $source): ?BotUser
    {
        try {
            $this->externalUser = ExternalUser::where([
                'external_id' => $external_id,
                'source' => $source,
            ])->first();

            if (empty($this->externalUser)) {
                throw new Exception('External user not found!');
            }

            return BotUser::where([
                'chat_id' => $this->externalUser->id,
                'platform' => $this->externalUser->source,
            ])->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return bool
     */
    public function isBanned(): bool
    {
        return $this->is_banned ?? false;
    }

    /**
     * Проверяет, было ли название топика изменено вручную
     *
     * @return bool
     */
    public function hasCustomTopicName(): bool
    {
        return (bool)$this->topic_name_edited && !empty($this->custom_topic_name);
    }

    /**
     * Сохраняет кастомное название топика
     *
     * @param string $topicName
     *
     * @return void
     */
    public function setCustomTopicName(string $topicName): void
    {
        // Обновляем модель только если она уже сохранена в БД
        if ($this->exists) {
            $this->refresh();
        }
        $this->custom_topic_name = $topicName;
        $this->topic_name_edited = true;
        $this->save();
    }

    /**
     * Получает название топика (кастомное или null)
     *
     * @return string|null
     */
    public function getCustomTopicName(): ?string
    {
        return $this->custom_topic_name;
    }

    /**
     * Очищает кастомное название топика
     *
     * @return void
     */
    public function clearCustomTopicName(): void
    {
        // Обновляем модель только если она уже сохранена в БД
        if ($this->exists) {
            $this->refresh();
        }
        $this->custom_topic_name = null;
        $this->topic_name_edited = false;
        $this->save();
    }

    /**
     * Проверяет, завершена ли регистрация пользователя
     *
     * @return bool
     */
    public function isRegistrationCompleted(): bool
    {
        return !empty($this->full_name) && 
               !empty($this->phone_number) && 
               !empty($this->email) && 
               !empty($this->registration_completed_at);
    }

    /**
     * Проверяет, нужна ли регистрация пользователю
     * Возвращает true, если отсутствуют все три обязательных поля
     *
     * @return bool
     */
    public function needsRegistration(): bool
    {
        return empty($this->full_name) || 
               empty($this->phone_number) || 
               empty($this->email);
    }

    /**
     * Присваивает порядковый номер пользователю
     * Использует транзакции и блокировки для предотвращения race conditions
     * Обрабатывает edge cases: deadlocks, unique constraint violations, удаление пользователя
     *
     * @return void
     */
    public function assignSequentialNumber(): void
    {
        // Если порядковый номер уже присвоен, ничего не делаем
        if ($this->sequential_number !== null) {
            return;
        }

        $attempts = 0;
        $maxAttempts = 3;
        $maxSequentialNumber = 4294967295; // Максимум для unsignedInteger

        while ($attempts < $maxAttempts) {
            try {
                // Используем транзакцию с блокировкой для безопасного присвоения
                DB::transaction(function () use ($maxSequentialNumber) {
                    // Обновляем модель из БД и блокируем строку для обновления
                    $this->refresh();
                    $this->lockForUpdate();

                    // Проверяем еще раз после блокировки (возможно, другой процесс уже присвоил номер)
                    if ($this->sequential_number !== null) {
                        return;
                    }

                    // Получаем максимальный порядковый номер
                    // В PostgreSQL нельзя использовать FOR UPDATE с агрегатными функциями
                    // Используем отдельный запрос для получения max, затем блокируем таблицу
                    $currentMax = DB::table('bot_users')
                        ->max('sequential_number') ?? 0;

                    // Проверяем, не достигнут ли максимум
                    if ($currentMax >= $maxSequentialNumber) {
                        throw new \Exception('Достигнут максимальный порядковый номер (' . $maxSequentialNumber . ')');
                    }

                    $this->sequential_number = $currentMax + 1;
                    $this->save();
                });

                // Обновляем модель из БД после транзакции, чтобы получить актуальное значение
                $this->refresh();
                break;
            } catch (QueryException $e) {
                // Обработка deadlock (40001) или unique constraint violation (23000)
                $isDeadlock = $e->getCode() === '40001';
                $isUniqueViolation = $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry');

                if (($isDeadlock || $isUniqueViolation) && $attempts < $maxAttempts - 1) {
                    $attempts++;
                    // Exponential backoff: 100ms, 200ms, 400ms
                    usleep(100000 * $attempts);

                    // Обновляем модель перед повторной попыткой
                    try {
                        $this->refresh();
                        // Если номер уже присвоен другим процессом, выходим
                        if ($this->sequential_number !== null) {
                            return;
                        }
                    } catch (\Throwable $refreshException) {
                        // Пользователь мог быть удален
                        Log::warning('assignSequentialNumber: не удалось обновить модель перед retry', [
                            'bot_user_id' => $this->id,
                            'error' => $refreshException->getMessage(),
                        ]);
                        return;
                    }

                    continue;
                }

                // Если это не deadlock/unique violation или превышено количество попыток
                Log::error('assignSequentialNumber: ошибка при присвоении порядкового номера', [
                    'bot_user_id' => $this->id,
                    'attempt' => $attempts + 1,
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage(),
                ]);
                throw $e;
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                // Пользователь был удален между проверкой и сохранением
                Log::warning('assignSequentialNumber: пользователь был удален', [
                    'bot_user_id' => $this->id ?? 'unknown',
                ]);
                return;
            } catch (\Throwable $e) {
                // Другие ошибки (например, достижение максимума)
                Log::error('assignSequentialNumber: неожиданная ошибка', [
                    'bot_user_id' => $this->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Если не удалось присвоить номер после всех попыток
        if ($this->sequential_number === null && $attempts >= $maxAttempts) {
            Log::error('assignSequentialNumber: не удалось присвоить порядковый номер после всех попыток', [
                'bot_user_id' => $this->id,
                'attempts' => $maxAttempts,
            ]);
        }
    }
}
