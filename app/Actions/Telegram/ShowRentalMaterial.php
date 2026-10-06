<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;

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
        $document = OfferDocument::fileId();

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
        $this->showTrustedLink($update, $botUser, 'keys');
    }

    /**
     * Отправить ссылку на график — только доверенным
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function showSchedule(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $this->showTrustedLink($update, $botUser, 'schedule');
    }

    /**
     * Ссылка из rental.links.{ключ}, которую видят только доверенные пользователи:
     * в меню для неё нет url-кнопки, ссылка приходит отдельным сообщением по нажатию
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     * @param string            $key
     *
     * @return void
     */
    private function showTrustedLink(TelegramUpdateDto $update, BotUser $botUser, string $key): void
    {
        if ($botUser->isBanned() || !$botUser->isTrusted()) {
            $this->ack($update->callbackId, __('messages.access_not_trusted'), true);
            return;
        }

        $link = config("rental.links.{$key}");

        if (empty($link)) {
            $this->ack($update->callbackId, __('messages.access_link_not_set'), true);
            return;
        }

        $this->ack($update->callbackId);

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __("messages.access_{$key}_message"),
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
