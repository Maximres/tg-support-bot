<?php

namespace App\Services\Broadcast;

use App\Models\BotSetting;

/**
 * Выключатель массовой рассылки: на время работ с ботом рассылку можно отключить из Telegram (/broadcast_off),
 * тогда сообщения в топике рассылки не уходят клиентам, а бот сообщает об этом в топике.
 */
class BroadcastSettings
{
    public const KEY_ENABLED = 'broadcast.enabled';

    /**
     * @return bool
     */
    public static function enabled(): bool
    {
        return BotSetting::get(self::KEY_ENABLED, '1') !== '0';
    }

    /**
     * @param bool $enabled
     *
     * @return void
     */
    public static function setEnabled(bool $enabled): void
    {
        BotSetting::set(self::KEY_ENABLED, $enabled ? '1' : '0');
    }
}
