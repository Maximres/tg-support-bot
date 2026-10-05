<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;

/**
 * Нажатие кнопки "Принимаю условия": фиксируем время согласия, убираем кнопку,
 * отправляем приветствие и закреплённое меню материалов, обновляем карточку в группе
 */
class AcceptOffer
{
    use AnswersCallbackQuery;

    /**
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, BotUser $botUser): void
    {
        if ($botUser->isBanned()) {
            $this->ack($update->callbackId);
            return;
        }

        if ($botUser->hasAcceptedOffer()) {
            $this->ack($update->callbackId, __('messages.offer_already_accepted'));
            return;
        }

        $botUser->offer_accepted_at = now();
        $botUser->save();

        $this->ack($update->callbackId, __('messages.offer_accepted_ack'));

        // Кнопка больше не нужна — убираем, чтобы не нажимали повторно
        if (!empty($update->messageId)) {
            TelegramMethods::sendQueryTelegram('editMessageReplyMarkup', [
                'chat_id' => $botUser->chat_id,
                'message_id' => $update->messageId,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        $phone = config('rental.emergency_phone');

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.offer_welcome_message', [
                'phone_line' => $phone ? __('messages.offer_phone_line', ['phone' => $phone]) : '',
            ]),
            'parse_mode' => 'html',
        ]));

        (new SendAccessMessage())->execute($botUser);

        (new UpdateContactMessage())->execute($botUser);
    }
}
