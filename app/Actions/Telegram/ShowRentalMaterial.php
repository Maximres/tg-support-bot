<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;
use App\Services\Rental\PricesImage;
use App\Enums\SafeCodeType;
use App\Services\Rental\RentalLinks;
use Illuminate\Support\Facades\Lang;

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
        // Старые закреплённые меню: Wi-Fi был ссылкой, теперь это текст
        if ($key === 'wifi') {
            (new ShowTrustedValue())->execute($update, $botUser, SafeCodeType::WIFI);
            return;
        }

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

        // «Цены»: если задана картинка, клиент получает её (со ссылкой кнопкой под ней, если она тоже задана)
        if ($key === 'prices' && PricesImage::fileId()) {
            $this->ack($update->callbackId);

            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from(array_filter([
                'methodQuery' => PricesImage::method(),
                'chat_id' => $botUser->chat_id,
                PricesImage::fileParam() => PricesImage::fileId(),
                'reply_markup' => $link ? ['inline_keyboard' => [[['text' => $this->buttonLabel($key), 'url' => $link]]]] : null,
            ], fn ($value) => $value !== null)));
            return;
        }

        if (empty($link)) {
            $this->ack($update->callbackId, __('messages.access_link_not_set'), true);
            return;
        }

        $this->ack($update->callbackId);

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => $this->messageText($key),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => $this->buttonLabel($key), 'url' => $link],
                    ],
                ],
            ],
        ]));
    }

    /**
     * Текст сообщения со ссылкой: свой для пункта (например, «График» с подсказкой про приложение), иначе общий
     *
     * @param string $key
     *
     * @return string
     */
    private function messageText(string $key): string
    {
        $custom = "messages.access_link_message_{$key}";

        if (Lang::has($custom)) {
            return __($custom, [
                'android' => config('rental.apps.sheets_android'),
                'ios' => config('rental.apps.sheets_ios'),
            ]);
        }

        return __('messages.access_link_message', ['title' => RentalLinks::title($key)]);
    }

    /**
     * Подпись кнопки со ссылкой: своя для пункта («Открыть график») или общая
     *
     * @param string $key
     *
     * @return string
     */
    private function buttonLabel(string $key): string
    {
        $custom = "messages.but_access_open_{$key}";

        return Lang::has($custom) ? __($custom) : __('messages.but_access_open_org_link');
    }
}
