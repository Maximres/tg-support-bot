<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendAccessMessageWithCallbackJob;
use App\Models\BotUser;
use App\Models\SafeCode;
use App\Services\Rental\OfferDocument;
use App\Services\Rental\RentalLinks;

/**
 * Одноразовая отправка и закрепление в личном чате сотрудника меню материалов.
 *
 * Пока администратор не открыл доступ (и после его отзыва) в меню только открытые всем пункты: описание кабинетов,
 * правила, как добраться и договор. С доступом — все материалы: ссылки и коды. Заблокированному клиенту — ничего. Ссылки в сообщение не вшиваются:
 * кнопки выдают актуальное значение при нажатии, поэтому ротация действует сразу.
 */
class SendAccessMessage
{
    /** Раскладка меню: каждая строка — ряд кнопок (слева направо), ряды идут сверху вниз */
    private const MENU_ROWS = [
        ['cabinets'],
        ['hub', 'map'],
        ['schedule', 'cancel'],
        ['prices', 'payment'],
        ['keys', 'codes'],
        ['groups', 'wifi'],
        ['org_link', 'offer'],
    ];

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
                'inline_keyboard' => $this->getKeyboard($botUser->hasMaterialsAccess(), $botUser->isBanned()),
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
     * Кнопки меню. Всем (кроме заблокированных) доступны описание кабинетов, правила, как добраться и договор;
     * график, ключи, коды и остальное — только при открытом доступе.
     * Пункты без заданного значения пропускаются.
     * Первая кнопка (описание кабинетов) — на всю ширину, остальные — по две в ряд.
     *
     * @param bool $hasAccess
     * @param bool $banned    Заблокированному клиенту не показываем ничего
     *
     * @return array
     */
    public function getKeyboard(bool $hasAccess = true, bool $banned = false): array
    {
        if ($banned) {
            return [];
        }

        // Кнопка по ключу пункта меню или null, если пункт не задан / недоступен клиенту
        $button = function (string $key) use ($hasAccess): ?array {
            return match ($key) {
                'org_link' => SafeCode::current(SafeCodeType::ORG_LINK)
                    ? ['text' => SafeCodeType::ORG_LINK->buttonLabel(), 'callback_data' => SafeCodeType::ORG_LINK->callbackData()]
                    : null,
                'wifi' => $hasAccess && SafeCode::current(SafeCodeType::WIFI)
                    ? ['text' => SafeCodeType::WIFI->buttonLabel(), 'callback_data' => SafeCodeType::WIFI->callbackData()]
                    : null,
                // Код сейфа и код здания — одна кнопка «Коды»
                'codes' => $hasAccess && (SafeCode::current(SafeCodeType::SAFE) || SafeCode::current(SafeCodeType::BUILDING))
                    ? ['text' => __('messages.but_menu_codes'), 'callback_data' => 'access_show_codes']
                    : null,
                'offer' => !empty(OfferDocument::fileId())
                    ? ['text' => __('messages.but_menu_offer'), 'callback_data' => 'offer_show']
                    : null,
                default => !empty(RentalLinks::get($key)) && ($hasAccess || in_array($key, RentalLinks::PUBLIC, true))
                    ? ['text' => __("messages.but_menu_{$key}"), 'callback_data' => "link_show_{$key}"]
                    : null,
            };
        };

        // Описание кабинетов сверху, затем ряды-пары по смыслу; если одной кнопки пары нет, вторая остаётся одна в ряду
        $keyboard = [];

        foreach (self::MENU_ROWS as $keys) {
            $row = array_values(array_filter(array_map($button, $keys)));

            if ($row !== []) {
                $keyboard[] = $row;
            }
        }

        return $keyboard;
    }
}
