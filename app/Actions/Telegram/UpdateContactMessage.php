<?php

declare(strict_types=1);

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendContactMessageWithCallbackJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Обновление контактного сообщения в топике
 * Редактирует существующее сообщение или отправляет новое, если сообщение не найдено
 */
class UpdateContactMessage
{
    /**
     * Обновить контактное сообщение
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        try {
            // Edge case: проверяем наличие topic_id
            if (empty($botUser->topic_id)) {
                Log::info('UpdateContactMessage: topic_id пустой, топик еще не создан', [
                    'bot_user_id' => $botUser->id ?? null,
                ]);
                return;
            }

            $sendContactMessage = new SendContactMessage();
            $text = $sendContactMessage->createContactMessage(
                $botUser->chat_id,
                $botUser->platform,
                $botUser->phone_number,
                $botUser->full_name,
                $botUser->email,
                $botUser->isBanned()
            );
            $keyboard = $sendContactMessage->getKeyboard($botUser);

            // Если есть сохраненный message_id, пытаемся редактировать сообщение
            if (!empty($botUser->contact_info_message_id)) {
                $edited = $this->editContactMessage($botUser, $text, $keyboard);
                
                if ($edited) {
                    Log::info('UpdateContactMessage: контактное сообщение успешно обновлено', [
                        'bot_user_id' => $botUser->id,
                        'message_id' => $botUser->contact_info_message_id,
                    ]);
                    return;
                }
                
                // Если редактирование не удалось (сообщение удалено), очищаем message_id
                Log::warning('UpdateContactMessage: не удалось отредактировать сообщение, отправляем новое', [
                    'bot_user_id' => $botUser->id,
                    'old_message_id' => $botUser->contact_info_message_id,
                ]);
                $botUser->contact_info_message_id = null;
                $botUser->save();
            }

            // Отправляем новое сообщение и сохраняем message_id
            $this->sendNewContactMessage($botUser, $text, $keyboard);
        } catch (\Throwable $e) {
            Log::error('UpdateContactMessage: ошибка при обновлении контактного сообщения', [
                'bot_user_id' => $botUser->id ?? null,
                'error' => $e->getMessage(),
            ]);
            (new LokiLogger())->logException($e);
        }
    }

    /**
     * Редактировать существующее контактное сообщение
     *
     * @param BotUser $botUser
     * @param string $text
     * @param array $keyboard
     *
     * @return bool
     */
    private function editContactMessage(BotUser $botUser, string $text, array $keyboard): bool
    {
        try {
            $response = TelegramMethods::sendQueryTelegram('editMessageText', [
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'message_thread_id' => $botUser->topic_id,
                'message_id' => $botUser->contact_info_message_id,
                'text' => $text,
                'parse_mode' => 'html',
                'reply_markup' => [
                    'inline_keyboard' => $keyboard,
                ],
            ]);

            if ($response->ok) {
                return true;
            }

            // Проверяем, не была ли ошибка из-за того, что сообщение не найдено
            $errorDescription = $response->description ?? '';
            if (str_contains($errorDescription, 'message to edit not found') ||
                str_contains($errorDescription, 'MESSAGE_NOT_FOUND')) {
                Log::info('UpdateContactMessage: сообщение не найдено, будет отправлено новое', [
                    'bot_user_id' => $botUser->id,
                    'message_id' => $botUser->contact_info_message_id,
                ]);
                return false;
            }

            Log::warning('UpdateContactMessage: ошибка редактирования сообщения', [
                'bot_user_id' => $botUser->id,
                'message_id' => $botUser->contact_info_message_id,
                'error' => $errorDescription,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('UpdateContactMessage: исключение при редактировании сообщения', [
                'bot_user_id' => $botUser->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Отправить новое контактное сообщение и сохранить message_id
     *
     * @param BotUser $botUser
     * @param string $text
     * @param array $keyboard
     *
     * @return void
     */
    private function sendNewContactMessage(BotUser $botUser, string $text, array $keyboard): void
    {
        $queryParams = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'text' => $text,
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => $keyboard,
            ],
        ]);

        // Отправляем через job с callback для сохранения message_id
        SendContactMessageWithCallbackJob::dispatch($botUser->id, $queryParams);
    }
}

