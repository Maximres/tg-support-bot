<?php

namespace App\Services\Registration;

use App\Logging\LokiLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления состоянием регистрации пользователей
 * Использует Redis для хранения временного состояния регистрации
 */
class UserRegistrationService
{
    /**
     * TTL для состояния регистрации (24 часа)
     */
    private const STATE_TTL = 86400; // 24 часа в секундах

    /**
     * Возможные состояния регистрации
     */
    public const STATE_WAITING_FULL_NAME = 'waiting_full_name';
    public const STATE_WAITING_PHONE = 'waiting_phone';
    public const STATE_WAITING_EMAIL = 'waiting_email';
    public const STATE_EDITING_FULL_NAME = 'editing_full_name';
    public const STATE_EDITING_PHONE = 'editing_phone';
    public const STATE_EDITING_EMAIL = 'editing_email';

    /**
     * Получить ключ для состояния регистрации пользователя
     *
     * @param int|string $chatId
     *
     * @return string
     */
    private function getStateKey(int|string $chatId): string
    {
        return "user_registration_state:{$chatId}";
    }

    /**
     * Получить ключ для блокировки (lock) при работе с состоянием
     *
     * @param int|string $chatId
     *
     * @return string
     */
    private function getLockKey(int|string $chatId): string
    {
        return "user_registration_lock:{$chatId}";
    }

    /**
     * Получить текущее состояние регистрации пользователя
     * Edge case: Redis недоступен - возвращает null, логирует ошибку
     *
     * @param int|string $chatId
     *
     * @return string|null
     */
    public function getState(int|string $chatId): ?string
    {
        try {
            $key = $this->getStateKey($chatId);
            $state = Cache::get($key);
            
            return $state;
        } catch (\Throwable $e) {
            // Edge case: Redis недоступен
            Log::warning('UserRegistrationService: ошибка получения состояния из Redis', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            (new LokiLogger())->sendBasicLog($e);
            
            return null;
        }
    }

    /**
     * Установить состояние регистрации пользователя
     * Edge case: Redis недоступен - логирует ошибку, но не прерывает выполнение
     *
     * @param int|string $chatId
     * @param string|null $state Состояние или null для очистки
     *
     * @return bool
     */
    public function setState(int|string $chatId, ?string $state): bool
    {
        try {
            $key = $this->getStateKey($chatId);
            
            if ($state === null) {
                // Очистка состояния
                Cache::forget($key);
                return true;
            }
            
            // Установка состояния с TTL
            Cache::put($key, $state, self::STATE_TTL);
            
            return true;
        } catch (\Throwable $e) {
            // Edge case: Redis недоступен
            Log::warning('UserRegistrationService: ошибка установки состояния в Redis', [
                'chat_id' => $chatId,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);
            (new LokiLogger())->sendBasicLog($e);
            
            return false;
        }
    }

    /**
     * Очистить состояние регистрации пользователя
     *
     * @param int|string $chatId
     *
     * @return bool
     */
    public function clearState(int|string $chatId): bool
    {
        return $this->setState($chatId, null);
    }

    /**
     * Проверить, находится ли пользователь в процессе регистрации
     *
     * @param int|string $chatId
     *
     * @return bool
     */
    public function isInRegistration(int|string $chatId): bool
    {
        $state = $this->getState($chatId);
        
        return $state !== null && in_array($state, [
            self::STATE_WAITING_FULL_NAME,
            self::STATE_WAITING_PHONE,
            self::STATE_WAITING_EMAIL,
        ]);
    }

    /**
     * Проверить, находится ли пользователь в режиме редактирования
     *
     * @param int|string $chatId
     *
     * @return bool
     */
    public function isEditing(int|string $chatId): bool
    {
        $state = $this->getState($chatId);
        
        return $state !== null && in_array($state, [
            self::STATE_EDITING_FULL_NAME,
            self::STATE_EDITING_PHONE,
            self::STATE_EDITING_EMAIL,
        ]);
    }

    /**
     * Получить блокировку для работы с состоянием (предотвращение race conditions)
     * Edge case: Redis недоступен - возвращает null, логирует ошибку
     *
     * @param int|string $chatId
     * @param int $timeout Таймаут блокировки в секундах (по умолчанию 10 секунд)
     *
     * @return \Illuminate\Contracts\Cache\Lock|null
     */
    public function getLock(int|string $chatId, int $timeout = 10): ?\Illuminate\Contracts\Cache\Lock
    {
        try {
            $lockKey = $this->getLockKey($chatId);
            return Cache::lock($lockKey, $timeout);
        } catch (\Throwable $e) {
            // Edge case: Redis недоступен
            Log::warning('UserRegistrationService: ошибка получения блокировки', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            (new LokiLogger())->sendBasicLog($e);
            
            return null;
        }
    }

    /**
     * Определить следующее состояние регистрации на основе текущего
     *
     * @param string $currentState
     *
     * @return string|null
     */
    public function getNextState(string $currentState): ?string
    {
        return match ($currentState) {
            self::STATE_WAITING_FULL_NAME => self::STATE_WAITING_PHONE,
            self::STATE_WAITING_PHONE => self::STATE_WAITING_EMAIL,
            self::STATE_WAITING_EMAIL => null, // Регистрация завершена
            default => null,
        };
    }

    /**
     * Валидация состояния (проверка на неизвестное состояние)
     *
     * @param string|null $state
     *
     * @return bool
     */
    public function isValidState(?string $state): bool
    {
        if ($state === null) {
            return true; // null - валидное состояние (регистрация не начата или завершена)
        }
        
        $validStates = [
            self::STATE_WAITING_FULL_NAME,
            self::STATE_WAITING_PHONE,
            self::STATE_WAITING_EMAIL,
            self::STATE_EDITING_FULL_NAME,
            self::STATE_EDITING_PHONE,
            self::STATE_EDITING_EMAIL,
        ];
        
        return in_array($state, $validStates);
    }
}

