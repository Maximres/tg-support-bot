<?php

namespace App\Services\Rental;

use App\Models\BotSetting;

/**
 * Картинка для кнопки «Цены»: клиент нажимает кнопку и получает её в ответ.
 * Администратор задаёт картинку командой /set_prices_image (или кнопкой в панели); file_id привязан к боту.
 */
class PricesImage
{
    public const KEY_FILE_ID = 'prices.file_id';

    /** photo — отправляем как фото, document — картинка пришла файлом (без сжатия) */
    public const KEY_TYPE = 'prices.type';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_DOCUMENT = 'document';

    /**
     * @return string|null
     */
    public static function fileId(): ?string
    {
        $fileId = BotSetting::get(self::KEY_FILE_ID);

        return !empty($fileId) ? (string)$fileId : null;
    }

    /**
     * Метод Telegram, которым картинка отправляется клиенту
     *
     * @return string sendPhoto|sendDocument
     */
    public static function method(): string
    {
        return BotSetting::get(self::KEY_TYPE) === self::TYPE_DOCUMENT ? 'sendDocument' : 'sendPhoto';
    }

    /**
     * Параметр с файлом в запросе Telegram (photo|document)
     *
     * @return string
     */
    public static function fileParam(): string
    {
        return self::method() === 'sendDocument' ? 'document' : 'photo';
    }

    /**
     * @param string $fileId
     * @param string $type
     *
     * @return void
     */
    public static function set(string $fileId, string $type = self::TYPE_PHOTO): void
    {
        BotSetting::set(self::KEY_FILE_ID, $fileId);
        BotSetting::set(self::KEY_TYPE, $type);
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        BotSetting::set(self::KEY_FILE_ID, null);
        BotSetting::set(self::KEY_TYPE, null);
    }
}
