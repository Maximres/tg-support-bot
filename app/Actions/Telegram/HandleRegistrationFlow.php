<?php

namespace App\Actions\Telegram;

use App\Actions\Telegram\UpdateContactMessage;
use App\Actions\Telegram\UpdateTopicName;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Jobs\TopicCreateJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Services\Registration\DataValidator;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Обработка потока регистрации пользователя
 * Обрабатывает последовательный сбор данных: ФИО, телефон, email
 */
class HandleRegistrationFlow
{
    private UserRegistrationService $registrationService;
    private DataValidator $validator;

    public function __construct()
    {
        $this->registrationService = new UserRegistrationService();
        $this->validator = new DataValidator();
    }

    /**
     * Обработать сообщение в контексте регистрации
     * Edge cases: Redis недоступен, некорректные данные, параллельные запросы
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool true если сообщение обработано, false если не относится к регистрации
     */
    public function execute(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        // Edge case: обработка только в приватных чатах
        if ($update->typeSource !== 'private') {
            return false;
        }

        // Edge case: параллельные запросы - используем блокировку
        $lock = $this->registrationService->getLock($update->chatId);
        $lockAcquired = false;
        
        if ($lock) {
            try {
                $lockAcquired = $lock->get();
                if (!$lockAcquired) {
                    // Не удалось получить блокировку - пропускаем обработку
                    Log::warning('HandleRegistrationFlow: не удалось получить блокировку', [
                        'chat_id' => $update->chatId,
                    ]);
                    return false;
                }
            } catch (\Throwable $e) {
                Log::warning('HandleRegistrationFlow: ошибка получения блокировки', [
                    'chat_id' => $update->chatId,
                    'error' => $e->getMessage(),
                ]);
                // Продолжаем без блокировки
            }
        }

        try {
            // Обработка контакта (кнопка "Поделиться номером")
            if (!empty($update->rawData['message']['contact'])) {
                $state = $this->registrationService->getState($update->chatId);
                
                // Если пользователь ожидает ввод телефона или редактирует телефон
                if ($state === UserRegistrationService::STATE_WAITING_PHONE || 
                    $state === UserRegistrationService::STATE_EDITING_PHONE) {
                    return $this->handlePhoneFromContact($update, $botUser, $state);
                }
                
                // Если не в процессе регистрации телефона, не обрабатываем
                return false;
            }

            // Edge case: только текстовые сообщения обрабатываем для регистрации
            if (empty($update->text)) {
                return false;
            }

            // Edge case: команды не обрабатываем в контексте регистрации
            // Команды обрабатываются отдельно в TelegramBotController
            if (str_starts_with($update->text, '/')) {
                return false;
            }

            $state = $this->registrationService->getState($update->chatId);
            
            // Edge case: неизвестное состояние - проверяем валидность
            if ($state !== null && !$this->registrationService->isValidState($state)) {
                Log::warning('HandleRegistrationFlow: неизвестное состояние, сбрасываем', [
                    'chat_id' => $update->chatId,
                    'state' => $state,
                ]);
                $this->registrationService->clearState($update->chatId);
                $state = null;
            }

            // Если нет состояния, проверяем данные в БД для синхронизации
            if ($state === null && $botUser->needsRegistration()) {
                // Edge case: потеря состояния Redis - определяем текущий шаг по данным
                $state = $this->determineStateFromData($botUser);
                if ($state) {
                    $this->registrationService->setState($update->chatId, $state);
                }
            }

            // Обработка состояния регистрации
            if ($state === UserRegistrationService::STATE_WAITING_FULL_NAME) {
                return $this->handleFullNameInput($update, $botUser);
            } elseif ($state === UserRegistrationService::STATE_WAITING_PHONE) {
                return $this->handlePhoneInput($update, $botUser);
            } elseif ($state === UserRegistrationService::STATE_WAITING_EMAIL) {
                return $this->handleEmailInput($update, $botUser);
            }

            // Обработка режима редактирования
            if ($state === UserRegistrationService::STATE_EDITING_FULL_NAME) {
                return $this->handleFullNameEdit($update, $botUser);
            } elseif ($state === UserRegistrationService::STATE_EDITING_PHONE) {
                return $this->handlePhoneEdit($update, $botUser);
            } elseif ($state === UserRegistrationService::STATE_EDITING_EMAIL) {
                return $this->handleEmailEdit($update, $botUser);
            }

            return false;
        } finally {
            // Освобождаем блокировку только если она была успешно получена
            if ($lock && $lockAcquired) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    Log::warning('HandleRegistrationFlow: ошибка освобождения блокировки', [
                        'chat_id' => $update->chatId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Определить состояние регистрации на основе данных в БД
     * Edge case: синхронизация состояния с данными
     *
     * @param BotUser $botUser
     *
     * @return string|null
     */
    private function determineStateFromData(BotUser $botUser): ?string
    {
        if (empty($botUser->full_name)) {
            return UserRegistrationService::STATE_WAITING_FULL_NAME;
        } elseif (empty($botUser->phone_number)) {
            return UserRegistrationService::STATE_WAITING_PHONE;
        } elseif (empty($botUser->email)) {
            return UserRegistrationService::STATE_WAITING_EMAIL;
        }

        return null;
    }

    /**
     * Обработать ввод ФИО
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function handleFullNameInput(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        $validation = $this->validator->validateFullName($update->text);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true; // Сообщение обработано (ошибка валидации)
        }

        // Сохранение с retry механизмом
        $saved = $this->saveUserData($botUser, [
            'full_name' => $validation['normalized'],
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем название топика и контактное сообщение, если топик уже создан
        if (!empty($botUser->topic_id)) {
            $this->updateTopicName($botUser);
            $this->updateContactMessage($botUser);
        }

        // Переход к следующему шагу
        $this->registrationService->setState($update->chatId, UserRegistrationService::STATE_WAITING_PHONE);
        $this->sendNextStepMessage($update, $botUser, 'phone');

        return true;
    }

    /**
     * Обработать телефон из контакта (кнопка "Поделиться номером")
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $state
     *
     * @return bool
     */
    private function handlePhoneFromContact(TelegramUpdateDto $update, BotUser $botUser, string $state): bool
    {
        $contactData = $update->rawData['message']['contact'] ?? [];
        $phoneNumber = $contactData['phone_number'] ?? null;

        if (empty($phoneNumber)) {
            $this->sendValidationError($update, $botUser, __('messages.registration.validation.phone_required'));
            return true;
        }

        // Валидация телефона из контакта
        $validation = $this->validator->validatePhone($phoneNumber);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true;
        }

        $saved = $this->saveUserData($botUser, [
            'phone_number' => $validation['normalized'],
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем название топика и контактное сообщение
        if (!empty($botUser->topic_id)) {
            $this->updateTopicName($botUser);
            $this->updateContactMessage($botUser);
        }

        // Если это регистрация, переходим к следующему шагу
        if ($state === UserRegistrationService::STATE_WAITING_PHONE) {
            $this->registrationService->setState($update->chatId, UserRegistrationService::STATE_WAITING_EMAIL);
            $this->sendNextStepMessage($update, $botUser, 'email');
        } else {
            // Если это редактирование, завершаем
            $this->registrationService->clearState($update->chatId);
            $this->sendEditSuccessMessage($update, $botUser, __('messages.registration.edit.phone_saved'));
        }

        return true;
    }

    /**
     * Обработать ввод телефона
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function handlePhoneInput(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        $validation = $this->validator->validatePhone($update->text);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true;
        }

        $saved = $this->saveUserData($botUser, [
            'phone_number' => $validation['normalized'],
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем название топика и контактное сообщение при сохранении телефона
        if (!empty($botUser->topic_id)) {
            $this->updateTopicName($botUser);
            $this->updateContactMessage($botUser);
        }

        // Переход к следующему шагу
        $this->registrationService->setState($update->chatId, UserRegistrationService::STATE_WAITING_EMAIL);
        $this->sendNextStepMessage($update, $botUser, 'email');

        return true;
    }

    /**
     * Обработать ввод email
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function handleEmailInput(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        $validation = $this->validator->validateEmail($update->text);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true;
        }

        $saved = $this->saveUserData($botUser, [
            'email' => $validation['normalized'],
            'registration_completed_at' => now(),
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем модель из БД для получения актуальных данных
        $botUser->refresh();

        // Edge case: создаем топик после завершения регистрации, если его еще нет
        if (empty($botUser->topic_id)) {
            $this->createTopicAfterRegistration($botUser);
        } else {
            // Обновляем название топика и контактное сообщение при сохранении email
            $this->updateTopicName($botUser);
            $this->updateContactMessage($botUser);
        }

        // Завершение регистрации
        $this->registrationService->clearState($update->chatId);
        $this->sendCompletionMessage($update, $botUser);

        return true;
    }

    /**
     * Обработать редактирование ФИО
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function handleFullNameEdit(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        $validation = $this->validator->validateFullName($update->text);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true;
        }

        $saved = $this->saveUserData($botUser, [
            'full_name' => $validation['normalized'],
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем название топика
        $this->updateTopicName($botUser);

        // Обновляем контактное сообщение в топике
        $this->updateContactMessage($botUser);

        // Завершение редактирования
        $this->registrationService->clearState($update->chatId);
        $this->sendEditSuccessMessage($update, $botUser, __('messages.registration.edit.full_name_saved'));

        return true;
    }

    /**
     * Обработать редактирование телефона
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function handlePhoneEdit(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        $validation = $this->validator->validatePhone($update->text);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true;
        }

        $saved = $this->saveUserData($botUser, [
            'phone_number' => $validation['normalized'],
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем название топика
        $this->updateTopicName($botUser);

        // Обновляем контактное сообщение в топике
        $this->updateContactMessage($botUser);

        // Завершение редактирования
        $this->registrationService->clearState($update->chatId);
        $this->sendEditSuccessMessage($update, $botUser, __('messages.registration.edit.phone_saved'));

        return true;
    }

    /**
     * Обработать редактирование email
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function handleEmailEdit(TelegramUpdateDto $update, BotUser $botUser): bool
    {
        $validation = $this->validator->validateEmail($update->text);

        if (!$validation['valid']) {
            $this->sendValidationError($update, $botUser, $validation['error']);
            return true;
        }

        $saved = $this->saveUserData($botUser, [
            'email' => $validation['normalized'],
        ]);

        if (!$saved) {
            $this->sendErrorMessage($update, $botUser, __('messages.registration.error.save_failed'));
            return true;
        }

        // Обновляем название топика
        $this->updateTopicName($botUser);

        // Обновляем контактное сообщение в топике
        $this->updateContactMessage($botUser);

        // Завершение редактирования
        $this->registrationService->clearState($update->chatId);
        $this->sendEditSuccessMessage($update, $botUser, __('messages.registration.edit.email_saved'));

        return true;
    }

    /**
     * Сохранить данные пользователя с retry механизмом
     * Edge case: ошибка сохранения, транзакции, пользователь удален
     *
     * @param BotUser $botUser
     * @param array $data
     *
     * @return bool
     */
    private function saveUserData(BotUser $botUser, array $data): bool
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                // Edge case: проверка существования пользователя перед сохранением
                $botUser->refresh();
                
                if (!$botUser->exists) {
                    Log::warning('HandleRegistrationFlow: пользователь был удален', [
                        'bot_user_id' => $botUser->id ?? null,
                    ]);
                    return false;
                }

                // Сохранение в транзакции
                DB::transaction(function () use ($botUser, $data) {
                    foreach ($data as $key => $value) {
                        $botUser->$key = $value;
                    }
                    $botUser->save();
                });

                return true;
            } catch (\Throwable $e) {
                $attempts++;
                
                if ($attempts >= $maxAttempts) {
                    Log::error('HandleRegistrationFlow: ошибка сохранения данных после всех попыток', [
                        'bot_user_id' => $botUser->id ?? null,
                        'data' => $data,
                        'error' => $e->getMessage(),
                    ]);
                    (new LokiLogger())->logException($e);
                    return false;
                }

                // Exponential backoff
                usleep(100000 * $attempts); // 100ms, 200ms
            }
        }

        return false;
    }

    /**
     * Отправить сообщение об ошибке валидации
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $errorMessage
     *
     * @return void
     */
    private function sendValidationError(TelegramUpdateDto $update, BotUser $botUser, string $errorMessage): void
    {
        $messageParamsDTO = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => $errorMessage,
            'parse_mode' => 'html',
        ]);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Отправить сообщение об ошибке
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $errorMessage
     *
     * @return void
     */
    private function sendErrorMessage(TelegramUpdateDto $update, BotUser $botUser, string $errorMessage): void
    {
        $messageParamsDTO = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => $errorMessage,
            'parse_mode' => 'html',
        ]);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Отправить сообщение о следующем шаге
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $step ('phone' или 'email')
     *
     * @return void
     */
    private function sendNextStepMessage(TelegramUpdateDto $update, BotUser $botUser, string $step): void
    {
            $messageKey = $step === 'phone' ? 'messages.registration.ask_phone' : 'messages.registration.ask_email';
        
        $messageParamsDTO = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => __($messageKey),
            'parse_mode' => 'html',
        ]);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Отправить сообщение о завершении регистрации
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendCompletionMessage(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $messageParamsDTO = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => __('messages.registration.completed'),
            'parse_mode' => 'html',
        ]);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Отправить сообщение об успешном редактировании
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $message
     *
     * @return void
     */
    private function sendEditSuccessMessage(TelegramUpdateDto $update, BotUser $botUser, string $message): void
    {
        $messageParamsDTO = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => $message,
            'parse_mode' => 'html',
        ]);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Создать топик после завершения регистрации
     * Edge case: ошибка создания топика не должна прерывать регистрацию
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    private function createTopicAfterRegistration(BotUser $botUser): void
    {
        try {
            // Создаем топик через очередь
            TopicCreateJob::dispatch($botUser->id);
            
            Log::info('HandleRegistrationFlow: создание топика после завершения регистрации запланировано', [
                'bot_user_id' => $botUser->id ?? null,
            ]);
            
            // После создания топика название будет обновлено автоматически через UpdateTopicName
            // в TopicCreateJob или при следующем сообщении от пользователя
        } catch (\Throwable $e) {
            // Edge case: ошибка создания топика не должна прерывать регистрацию
            Log::warning('HandleRegistrationFlow: ошибка создания топика после регистрации', [
                'bot_user_id' => $botUser->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обновить название топика
     * Edge case: топик не создан, ошибка обновления
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    private function updateTopicName(BotUser $botUser): void
    {
        try {
            if (!empty($botUser->topic_id)) {
                (new UpdateTopicName())->execute($botUser);
            }
        } catch (\Throwable $e) {
            // Edge case: ошибка обновления названия не должна прерывать регистрацию
            Log::warning('HandleRegistrationFlow: ошибка обновления названия топика', [
                'bot_user_id' => $botUser->id ?? null,
                'topic_id' => $botUser->topic_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обновить контактное сообщение в топике
     * Edge case: топик не создан, ошибка обновления
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    private function updateContactMessage(BotUser $botUser): void
    {
        try {
            if (!empty($botUser->topic_id)) {
                (new UpdateContactMessage())->execute($botUser);
            }
        } catch (\Throwable $e) {
            // Edge case: ошибка обновления контактного сообщения не должна прерывать регистрацию
            Log::warning('HandleRegistrationFlow: ошибка обновления контактного сообщения', [
                'bot_user_id' => $botUser->id ?? null,
                'topic_id' => $botUser->topic_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

