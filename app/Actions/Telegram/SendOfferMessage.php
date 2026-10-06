<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;

/**
 * Отправка сотруднику договора-оферты с кнопкой "Принимаю условия".
 * Если условия уже приняты — вместо этого отправляется меню материалов.
 */
class SendOfferMessage
{
    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        if ($botUser->platform !== 'telegram' || empty($botUser->chat_id) || $botUser->isBanned()) {
            return;
        }

        if ($botUser->hasAcceptedOffer()) {
            (new SendAccessMessage())->execute($botUser);
            return;
        }

        $document = OfferDocument::fileId();

        $params = [
            'chat_id' => $botUser->chat_id,
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('messages.but_offer_accept'), 'callback_data' => 'offer_accept'],
                    ],
                ],
            ],
        ];

        if (!empty($document)) {
            $params['methodQuery'] = 'sendDocument';
            $params['document'] = $document;
            $params['caption'] = __('messages.offer_message_text');
        } else {
            $params['methodQuery'] = 'sendMessage';
            $params['text'] = __('messages.offer_message_text');
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from($params));
    }
}
