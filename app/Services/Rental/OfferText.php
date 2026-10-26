<?php

namespace App\Services\Rental;

use App\Models\BotSetting;

/**
 * Текст сообщения с договором-офертой (подпись к PDF). Владелец меняет его из Telegram командой /set_offer_text,
 * текст из БД главнее запасного текста из resources/lang.
 */
class OfferText
{
    public const KEY_TEXT = 'offer.text';

    /** Подпись к документу у бота — до 1024 символов; оставляем запас */
    public const MAX_LENGTH = 1000;

    /**
     * HTML-текст, который нужно отправить клиенту
     *
     * @return string
     */
    public static function html(): string
    {
        $custom = BotSetting::get(self::KEY_TEXT);

        return !empty($custom) ? $custom : __('messages.offer_message_text');
    }

    /**
     * @return bool
     */
    public static function isCustom(): bool
    {
        return !empty(BotSetting::get(self::KEY_TEXT));
    }

    /**
     * @param string $html
     *
     * @return void
     */
    public static function set(string $html): void
    {
        BotSetting::set(self::KEY_TEXT, $html);
    }

    /**
     * Вернуть текст по умолчанию
     *
     * @return void
     */
    public static function reset(): void
    {
        BotSetting::set(self::KEY_TEXT, null);
    }
}
