<?php

namespace App\Services\Rental;

use App\Models\BotSetting;

/**
 * Актуальный PDF договора-оферты. Администратор заменяет его командой /set_offer в группе;
 * значение из БД главнее RENTAL_OFFER_DOCUMENT из .env (тот остаётся запасным вариантом)
 */
class OfferDocument
{
    public const KEY_FILE_ID = 'offer.file_id';

    public const KEY_FILE_NAME = 'offer.file_name';

    public const KEY_UPDATED_AT = 'offer.updated_at';

    /**
     * file_id (или URL) договора, который нужно отправлять клиентам
     *
     * @return string|null
     */
    public static function fileId(): ?string
    {
        $fromDb = BotSetting::get(self::KEY_FILE_ID);

        if (!empty($fromDb)) {
            return $fromDb;
        }

        $fromConfig = config('rental.offer_document');

        return !empty($fromConfig) ? (string)$fromConfig : null;
    }

    /**
     * @return string|null имя файла, под которым договор загружен через /set_offer
     */
    public static function fileName(): ?string
    {
        return BotSetting::get(self::KEY_FILE_NAME);
    }

    /**
     * @param string      $fileId
     * @param string|null $fileName
     *
     * @return void
     */
    public static function set(string $fileId, ?string $fileName): void
    {
        BotSetting::set(self::KEY_FILE_ID, $fileId);
        BotSetting::set(self::KEY_FILE_NAME, $fileName);
        BotSetting::set(self::KEY_UPDATED_AT, now('UTC')->toDateTimeString());
    }
}
