<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;
use App\Services\Rental\RentalLinks;

/**
 * Пункты меню материалов, которые не сводятся к простому показу значения:
 * повторный показ договора (всем) и ссылки на материалы (описание кабинетов и как добраться — всем,
 * остальные — только при открытом доступе)
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
     * Ссылка на ключи — для совместимости со старыми закреплёнными меню
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function showKeys(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $this->showLink($update, $botUser, 'keys');
    }

    /**
     * Ссылка на график — для совместимости со старыми закреплёнными меню
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function showSchedule(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $this->showLink($update, $botUser, 'schedule');
    }

    /**
     * Актуальная ссылка из RentalLinks, только при открытом доступе. В закреплённом меню ссылок нет:
     * бот при каждом нажатии берёт текущее значение, поэтому после ротации старая ссылка не работает
     * ни из какого старого сообщения
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     * @param string            $key
     *
     * @return void
     */
    public function showLink(TelegramUpdateDto $update, BotUser $botUser, string $key): void
    {
        if (!in_array($key, RentalLinks::KEYS, true)) {
            $this->ack($update->callbackId);
            return;
        }

        $isAllowed = in_array($key, RentalLinks::PUBLIC, true)
            ? !$botUser->isBanned()
            : $botUser->hasMaterialsAccess();

        if (!$isAllowed) {
            $this->ack($update->callbackId, __('messages.access_not_trusted'), true);
            return;
        }

        $link = RentalLinks::get($key);

        if (empty($link)) {
            $this->ack($update->callbackId, __('messages.access_link_not_set'), true);
            return;
        }

        $this->ack($update->callbackId);

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.access_link_message', ['title' => RentalLinks::title($key)]),
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
