<?php

namespace App\Services\Rental;

use App\Models\BotSetting;

/**
 * Ссылки меню арендатора. Текущее значение хранится в БД и меняется администратором
 * из группы (ротация); значение из .env (config/rental.php) остаётся запасным.
 *
 * Ссылки никогда не вшиваются в закреплённое меню: кнопка каждый раз берёт актуальное
 * значение, поэтому после смены старая ссылка у клиента в чате не остаётся.
 */
class RentalLinks
{
    /** Пометка в БД: пункт скрыт из меню, даже если значение есть в .env */
    public const DISABLED = '-';

    public const KEYS = ['hub', 'cabinets', 'map', 'schedule', 'payment', 'wifi', 'keys'];

    /** Ссылки, доступные всем клиентам без открытия доступа */
    public const PUBLIC = ['cabinets', 'map'];

    /** Названия, которые администратор может написать в /set_link => ключ */
    private const ALIASES = [
        'hub' => ['hub', 'инструкции', 'инструкция'],
        'cabinets' => ['cabinets', 'кабинеты', 'кабинет'],
        'map' => ['map', 'карта'],
        'schedule' => ['schedule', 'график'],
        'payment' => ['payment', 'оплата'],
        'wifi' => ['wifi', 'wi-fi', 'вайфай'],
        'keys' => ['keys', 'ключи', 'ключ'],
    ];

    /**
     * @param string $key
     *
     * @return string|null
     */
    public static function get(string $key): ?string
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $fromDb = BotSetting::get(self::settingKey($key));

        if ($fromDb === self::DISABLED) {
            return null;
        }

        if (!empty($fromDb)) {
            return $fromDb;
        }

        $fromConfig = config("rental.links.{$key}");

        return !empty($fromConfig) ? (string)$fromConfig : null;
    }

    /**
     * @param string $key
     * @param string $url
     *
     * @return void
     */
    public static function set(string $key, string $url): void
    {
        BotSetting::set(self::settingKey($key), $url);
    }

    /**
     * Название пункта для текстов и кнопок («График», «Wi-Fi»…)
     *
     * @param string $key
     *
     * @return string
     */
    public static function title(string $key): string
    {
        return __("messages.link_titles.{$key}");
    }

    /**
     * Ключ ссылки по названию из команды (русскому или английскому)
     *
     * @param string $name
     *
     * @return string|null
     */
    public static function resolve(string $name): ?string
    {
        $name = mb_strtolower(trim($name));

        foreach (self::ALIASES as $key => $aliases) {
            if (in_array($name, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Допустимые названия для подсказки администратору
     *
     * @return string
     */
    public static function namesHint(): string
    {
        return implode(', ', array_map(fn ($aliases) => $aliases[1] ?? $aliases[0], self::ALIASES));
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    public static function isValidUrl(string $url): bool
    {
        return mb_strlen($url) <= 512
            && !preg_match('/\s/u', $url)
            && (bool)preg_match('#^https?://[^\s/$.?\#].[^\s]*$#iu', $url);
    }

    /**
     * Скрыть пункт из меню
     *
     * @param string $key
     *
     * @return void
     */
    public static function disable(string $key): void
    {
        BotSetting::set(self::settingKey($key), self::DISABLED);
    }

    /**
     * Слово, которым администратор отключает пункт: /set_link график off
     *
     * @param string $value
     *
     * @return bool
     */
    public static function isOffWord(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['off', 'выкл', 'откл', 'убрать', '-'], true);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private static function settingKey(string $key): string
    {
        return "link.{$key}";
    }
}
