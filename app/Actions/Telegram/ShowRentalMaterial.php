<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;

/**
 * Пункты меню материалов, которые не сводятся к простой ссылке:
 * повторный показ договора и ссылка на ключи (только для доверенных)
 */
class ShowRentalMaterial
{
    use AnswersCallbackQuery;

    /**
     * Повторно отправить договор-оферту (без кнопки согласия)
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function showOffer(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $document = config('rental.offer_document');

        if ($botUser->isBanned()) {
            $this->ack($update->callbackId);
            return;
        }

        if (empty($document)) {
            $this->ack($update->callbackId, __('messages.offer_document_not_set'), true);
            return;
        }

        $this->ack($update->callbackId);

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendDocument',
            'chat_id' => $botUser->chat_id,
            'document' => $document,
        ]));
    }

    /**
     * Отправить ссылку на инструкцию по ключам — только доверенным
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function showKeys(TelegramUpdateDto $update, BotUser $botUser): void
    {
        if ($botUser->isBanned() || !$botUser->isTrusted()) {
            $this->ack($update->callbackId, __('messages.access_not_trusted'), true);
            return;
        }

        $link = config('rental.links.keys');

        if (empty($link)) {
            $this->ack($update->callbackId, __('messages.access_link_not_set'), true);
            return;
        }

        $this->ack($update->callbackId);

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.access_keys_message'),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('messages.but_access_open_org_link'), 'url' => $link],
                    ],
                ],
            ],
        ]));
    }
}
