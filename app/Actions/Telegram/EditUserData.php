<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Support\Facades\Log;

/**
 * Обработка редактирования данных пользователя
 */
class EditUserData
{
    private UserRegistrationService $registrationService;

    public function __construct()
    {
        $this->registrationService = new UserRegistrationService();
    }

    /**
     * Начать редактирование поля данных
     * Edge case: обработка только в приватных чатах, проверка наличия данных
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $field 'full_name', 'phone', или 'email'
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, BotUser $botUser, string $field): void
    {
        // Edge case: обработка только в приватных чатах
        if ($update->typeSource !== 'private') {
            Log::warning('EditUserData: команда вызвана не в приватном чате', [
                'chat_id' => $update->chatId,
                'type_source' => $update->typeSource,
                'field' => $field,
            ]);
            return;
        }

        try {
            // Определяем состояние редактирования
            $state = match ($field) {
                'full_name' => UserRegistrationService::STATE_EDITING_FULL_NAME,
                'phone' => UserRegistrationService::STATE_EDITING_PHONE,
                'email' => UserRegistrationService::STATE_EDITING_EMAIL,
                default => null,
            };

            if ($state === null) {
                Log::warning('EditUserData: неизвестное поле для редактирования', [
                    'chat_id' => $update->chatId,
                    'field' => $field,
                ]);
                return;
            }

            // Устанавливаем состояние редактирования
            // Edge case: очищаем состояние регистрации при начале редактирования
            // Это позволяет прервать регистрацию и начать редактирование
            $this->registrationService->setState($update->chatId, $state);

            // Определяем сообщение для запроса нового значения
            $messageKey = match ($field) {
                'full_name' => 'registration.edit.ask_full_name',
                'phone' => 'registration.edit.ask_phone',
                'email' => 'registration.edit.ask_email',
                default => null,
            };

            if ($messageKey === null) {
                return;
            }

            // Получаем текущее значение для отображения
            $currentValue = match ($field) {
                'full_name' => $botUser->full_name,
                'phone' => $botUser->phone_number,
                'email' => $botUser->email,
                default => null,
            };

            $text = __($messageKey);
            
            // Если есть текущее значение, показываем его
            if (!empty($currentValue)) {
                $currentLabel = match ($field) {
                    'full_name' => __('registration.my_data.full_name'),
                    'phone' => __('registration.my_data.phone'),
                    'email' => __('registration.my_data.email'),
                    default => '',
                };
                $text .= "\n\n" . __('registration.edit.current_value', [
                    'field' => $currentLabel,
                    'value' => $currentValue,
                ]);
            }

            $text .= "\n\n" . __('registration.edit.cancel_hint');

            $messageParams = [
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'text' => $text,
                'parse_mode' => 'html',
            ];

            $messageParamsDTO = TGTextMessageDto::from($messageParams);

            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                $messageParamsDTO,
                'outgoing'
            );
        } catch (\Throwable $e) {
            Log::error('EditUserData: ошибка начала редактирования', [
                'chat_id' => $update->chatId,
                'bot_user_id' => $botUser->id ?? null,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отменить редактирование
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return void
     */
    public function cancel(TelegramUpdateDto $update, BotUser $botUser): void
    {
        // Edge case: обработка только в приватных чатах
        if ($update->typeSource !== 'private') {
            return;
        }

        try {
            // Очищаем состояние редактирования
            $this->registrationService->clearState($update->chatId);

            $messageParams = [
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'text' => __('registration.edit.cancelled'),
                'parse_mode' => 'html',
            ];

            $messageParamsDTO = TGTextMessageDto::from($messageParams);

            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                $messageParamsDTO,
                'outgoing'
            );
        } catch (\Throwable $e) {
            Log::error('EditUserData: ошибка отмены редактирования', [
                'chat_id' => $update->chatId,
                'bot_user_id' => $botUser->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

