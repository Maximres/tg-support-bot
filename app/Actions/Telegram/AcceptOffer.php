<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;

/**
 * Нажатие кнопки "Принимаю условия": фиксируем время согласия, убираем кнопку и отправляем
 * закреплённое меню одним сообщением (с благодарностью); карточка в группе обновляется
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

        // force: у сотрудников, зарегистрированных до появления оферты, уже может быть закреплено
        // старое меню — заменяем его актуальным (старый пин снимается)
        (new SendAccessMessage())->execute($botUser, true, __('messages.offer_accepted_intro'));

        (new UpdateContactMessage())->execute($botUser);
    }
}
