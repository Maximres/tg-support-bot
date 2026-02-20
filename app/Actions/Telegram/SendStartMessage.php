<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use App\Services\Registration\UserRegistrationService;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Отправка стартового сообщения
 */
class SendStartMessage
{
    private UserRegistrationService $registrationService;

    public function __construct()
    {
        $this->registrationService = new UserRegistrationService();
    }

    /**
     * Отправка стартового сообщения
     * Edge case: определение первого контакта, запуск регистрации
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update): void
    {
        TelegramMethods::sendQueryTelegram('deleteMessage', [
            'chat_id' => $update->chatId,
            'message_id' => $update->messageId,
        ]);

        if ($update->typeSource === 'private') {
            $botUser = BotUser::getOrCreateByTelegramUpdate($update);
            
            // Edge case: определение первого контакта
            // Если пользователь нуждается в регистрации и нет состояния в Redis
            if ($botUser->needsRegistration() && !$this->registrationService->getState($update->chatId)) {
                // Запуск процесса регистрации
                $this->startRegistration($update, $botUser);
                return;
            }

            // Если пользователь уже зарегистрирован, показываем обычное стартовое сообщение
            // или меню с данными
            if ($botUser->isRegistrationCompleted()) {
                $this->sendStartMessageForRegisteredUser($update, $botUser);
            } else {
                // Пользователь частично зарегистрирован - показываем стартовое сообщение
                $this->sendStartMessage($update, $botUser);
            }
        }
    }

    /**
     * Запустить процесс регистрации
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return void
     */
    private function startRegistration(TelegramUpdateDto $update, BotUser $botUser): void
    {
        try {
            // Определяем начальное состояние на основе данных
            $initialState = $this->determineInitialState($botUser);
            
            // Устанавливаем состояние регистрации
            $this->registrationService->setState($update->chatId, $initialState);
            
            // Отправляем приветственное сообщение
            $messageParams = [
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'text' => __('messages.registration.welcome'),
                'parse_mode' => 'html',
            ];

            $messageParamsDTO = TGTextMessageDto::from($messageParams);

            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                $messageParamsDTO,
                'outgoing'
            );

            // Отправляем запрос первого поля
            $this->sendFirstFieldRequest($update, $botUser, $initialState);
        } catch (\Throwable $e) {
            Log::error('SendStartMessage: ошибка запуска регистрации', [
                'chat_id' => $update->chatId,
                'bot_user_id' => $botUser->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback на обычное стартовое сообщение
            $this->sendStartMessage($update, $botUser);
        }
    }

    /**
     * Определить начальное состояние регистрации на основе данных пользователя
     *
     * @param BotUser $botUser
     *
     * @return string
     */
    private function determineInitialState(BotUser $botUser): string
    {
        if (empty($botUser->full_name)) {
            return UserRegistrationService::STATE_WAITING_FULL_NAME;
        } elseif (empty($botUser->phone_number)) {
            return UserRegistrationService::STATE_WAITING_PHONE;
        } elseif (empty($botUser->email)) {
            return UserRegistrationService::STATE_WAITING_EMAIL;
        }

        // Если все поля заполнены, но registration_completed_at не установлен
        return UserRegistrationService::STATE_WAITING_FULL_NAME;
    }

    /**
     * Отправить запрос первого поля регистрации
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     * @param string $state
     *
     * @return void
     */
    private function sendFirstFieldRequest(TelegramUpdateDto $update, BotUser $botUser, string $state): void
    {
        $messageKey = match ($state) {
            UserRegistrationService::STATE_WAITING_FULL_NAME => 'messages.registration.ask_full_name',
            UserRegistrationService::STATE_WAITING_PHONE => 'messages.registration.ask_phone',
            UserRegistrationService::STATE_WAITING_EMAIL => 'messages.registration.ask_email',
            default => 'messages.registration.ask_full_name',
        };

        $messageParams = [
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => __($messageKey),
            'parse_mode' => 'html',
        ];

        $messageParamsDTO = TGTextMessageDto::from($messageParams);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Отправить стартовое сообщение для зарегистрированного пользователя
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendStartMessageForRegisteredUser(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $messageParams = [
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => __('messages.start'),
            'parse_mode' => 'html',
        ];

        $messageParamsDTO = TGTextMessageDto::from($messageParams);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }

    /**
     * Отправить обычное стартовое сообщение
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendStartMessage(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $messageParams = [
            'methodQuery' => 'sendMessage',
            'chat_id' => $update->chatId,
            'text' => __('messages.start'),
            'parse_mode' => 'html',
        ];

        // Добавляем кнопку запроса номера, если у пользователя еще нет номера
        if (empty($botUser->phone_number)) {
            $messageParams['reply_markup'] = [
                'keyboard' => [
                    [
                        [
                            'text' => __('messages.but_request_phone'),
                            'request_contact' => true,
                        ],
                    ],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ];
        }

        $messageParamsDTO = TGTextMessageDto::from($messageParams);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            $messageParamsDTO,
            'outgoing'
        );
    }
}
