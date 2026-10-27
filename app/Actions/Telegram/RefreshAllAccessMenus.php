<?php

namespace App\Actions\Telegram;

use App\Models\BotUser;
use Illuminate\Support\Facades\Cache;

/**
 * Приводит закреплённое меню у всех клиентов в соответствие с текущей раскладкой кнопок и их доступом.
 * Кнопки правятся на месте: клиентам не приходит ни новых сообщений, ни уведомлений.
 * Одновременно работает только один запуск (повторная доставка команды Telegram не запустит второй).
 */
class RefreshAllAccessMenus
{
    private const CHUNK = 20;

    private const LOCK = 'refresh_all_access_menus';

    /**
     * @return array{done: int, failed: int}|null null, если обновление уже идёт
     */
    public function execute(): ?array
    {
        $lock = Cache::lock(self::LOCK, 600);

        if (!$lock->get()) {
            return null;
        }

        try {
            $refresher = new RefreshAccessMessage();
            $done = 0;
            $failed = 0;

            BotUser::query()
                ->where('platform', 'telegram')
                ->whereNotNull('chat_id')
                ->whereNotNull('access_message_id')
                // Без принятого договора меню клиенту не нужно: оно появится после согласия
                ->whereNotNull('offer_accepted_at')
                ->chunkById(self::CHUNK, function ($botUsers) use ($refresher, &$done, &$failed) {
                    foreach ($botUsers as $botUser) {
                        $refresher->execute($botUser) ? $done++ : $failed++;
                    }

                    // Пауза между пачками, чтобы не упереться в лимиты Telegram
                    if ($botUsers->count() === self::CHUNK) {
                        sleep(1);
                    }
                });

            return ['done' => $done, 'failed' => $failed];
        } finally {
            $lock->release();
        }
    }
}
