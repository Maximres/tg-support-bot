<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendAccessMessageWithCallbackJob;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;
use App\Services\Rental\RentalLinks;

/**
 * Одноразовая отправка и закрепление в личном чате сотрудника меню материалов.
 *
 * Пока администратор не открыл доступ (и после его отзыва или блокировки) в меню только договор.
 * С доступом — все материалы: ссылки, коды, орг. информация. Ссылки в сообщение не вшиваются:
 * кнопки выдают актуальное значение при нажатии, поэтому ротация действует сразу.
 */
class SendAccessMessage
{
    /**
     * @param BotUser $botUser
     * @param bool    $force Отправить заново, даже если сообщение уже отправлялось ранее
     *                       (например, сотрудник случайно удалил закреплённое сообщение)
     * @param string|null $intro Вступительная строка над текстом меню (например, благодарность за согласие с офертой)
     *
     * @return void
     */
    public function execute(BotUser $botUser, bool $force = false, ?string $intro = null): void
    {
        if ($botUser->platform !== 'telegram' || empty($botUser->chat_id)) {
            return;
        }

        if ($botUser->isBanned() || (!$force && $botUser->hasAccessMessage())) {
            return;
        }

        $queryParams = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => $this->buildText($botUser, $intro),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => $this->getKeyboard($botUser->hasMaterialsAccess()),
            ],
        ]);

        SendAccessMessageWithCallbackJob::dispatch($botUser->id, $queryParams, $force);
    }

    /**
     * Текст закреплённого сообщения для текущего состояния доступа
     *
     * @param BotUser     $botUser
     * @param string|null $intro
     *
     * @return string
     */
    public function buildText(BotUser $botUser, ?string $intro = null): string
    {
        $phone = config('rental.emergency_phone');

        $text = __($botUser->hasMaterialsAccess() ? 'messages.access_message_text' : 'messages.access_message_text_locked', [
            'phone_line' => $phone ? __('messages.access_phone_line', ['phone' => $phone]) : '',
        ]);

        return $intro ? $intro . "\n\n" . $text : $text;
    }

    /**
     * Кнопки меню. Без доступа — только договор; с доступом — всё остальное.
     * Ссылочные пункты без заданного значения пропускаются.
     * Первая кнопка (описание кабинетов) — на всю ширину, остальные — по две в ряд,
     * начиная с общей страницы инструкций.
     *
     * @param bool $hasAccess
     *
     * @return array
     */
    public function getKeyboard(bool $hasAccess = true): array
    {
        $offerButton = !empty(OfferDocument::fileId())
            ? ['text' => __('messages.but_menu_offer'), 'callback_data' => 'offer_show']
            : null;

        if (!$hasAccess) {
            return $offerButton ? [[$offerButton]] : [];
        }

        $buttons = [];

        foreach (['hub', 'map', 'schedule', 'payment', 'wifi', 'keys'] as $key) {
            if (!empty(RentalLinks::get($key))) {
                $buttons[] = ['text' => __("messages.but_menu_{$key}"), 'callback_data' => "link_show_{$key}"];
            }
        }

        foreach ([SafeCodeType::ORG_LINK, SafeCodeType::SAFE, SafeCodeType::BUILDING] as $type) {
            $buttons[] = ['text' => $type->buttonLabel(), 'callback_data' => $type->callbackData()];
        }

        if ($offerButton) {
            $buttons[] = $offerButton;
        }

        $keyboard = [];

        if (!empty(RentalLinks::get('cabinets'))) {
            $keyboard[] = [['text' => __('messages.but_menu_cabinets'), 'callback_data' => 'link_show_cabinets']];
        }

        return array_merge($keyboard, array_chunk($buttons, 2));
    }
}
