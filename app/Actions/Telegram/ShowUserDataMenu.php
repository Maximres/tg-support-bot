<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Support\Facades\Log;

/**
 * Показ меню с данными пользователя и возможностью редактирования
 */
class ShowUserDataMenu
{
    private UserRegistrationService $registrationService;

    public function __construct()
    {
        $this->registrationService = new UserRegistrationService();
    }

    /**
     * Показать меню с данными пользователя
     * Edge case: обработка только в приватных чатах, проверка наличия данных
     *
     * @param TelegramUpdateDto $update
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, BotUser $botUser): void
    {
        // Edge case: обработка только в приватных чатах
        if ($update->typeSource !== 'private') {
            Log::warning('ShowUserDataMenu: команда вызвана не в приватном чате', [
                'chat_id' => $update->chatId,
                'type_source' => $update->typeSource,
            ]);
            return;
        }

        try {
            // Формируем текст с данными пользователя
            $text = __('registration.my_data.header') . "\n\n";
            
            $fullName = $botUser->full_name ?? __('registration.my_data.not_provided');
            $phone = $botUser->phone_number ?? __('registration.my_data.not_provided');
            $email = $botUser->email ?? __('registration.my_data.not_provided');
            
            $text .= __('registration.my_data.full_name') . ": <b>{$fullName}</b>\n";
            $text .= __('registration.my_data.phone') . ": <b>{$phone}</b>\n";
            $text .= __('registration.my_data.email') . ": <b>{$email}</b>\n\n";
            
            $text .= __('registration.edit_menu.instructions');

            // Формируем клавиатуру для редактирования
            $keyboard = [
                [
                    [
                        'text' => __('registration.edit_menu.edit_full_name'),
                        'callback_data' => 'edit_full_name',
                    ],
                ],
                [
                    [
                        'text' => __('registration.edit_menu.edit_phone'),
                        'callback_data' => 'edit_phone',
                    ],
                ],
                [
                    [
                        'text' => __('registration.edit_menu.edit_email'),
                        'callback_data' => 'edit_email',
                    ],
                ],
            ];

            // Добавляем кнопку отмены, если пользователь в режиме редактирования
            if ($this->registrationService->isEditing($update->chatId)) {
                $keyboard[] = [
                    [
                        'text' => __('registration.edit_menu.cancel'),
                        'callback_data' => 'cancel_edit',
                    ],
                ];
            }

            $messageParams = [
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'text' => $text,
                'parse_mode' => 'html',
                'reply_markup' => [
                    'inline_keyboard' => $keyboard,
                ],
            ];

            $messageParamsDTO = TGTextMessageDto::from($messageParams);

            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                $messageParamsDTO,
                'outgoing'
            );
        } catch (\Throwable $e) {
            Log::error('ShowUserDataMenu: ошибка показа меню', [
                'chat_id' => $update->chatId,
                'bot_user_id' => $botUser->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

