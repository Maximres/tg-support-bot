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
     * @param bool $clean Заодно убрать у клиентов старые копии меню из закреплённых (глубоко, см. PruneOldMenuPins)
     *
     * @return array{done: int, failed: int, removed: int}|null removed — у скольких клиентов пересобраны закрепы; null, если обновление уже идёт
     */
    public function execute(bool $clean = false): ?array
    {
        $lock = Cache::lock(self::LOCK, 600);

        if (!$lock->get()) {
            return null;
        }

        try {
            $refresher = new RefreshAccessMessage();
            $done = 0;
            $failed = 0;
            $removed = 0;

            BotUser::query()
                ->where('platform', 'telegram')
                ->whereNotNull('chat_id')
                ->whereNotNull('access_message_id')
                // Без принятого договора меню клиенту не нужно: оно появится после согласия
                ->whereNotNull('offer_accepted_at')
                ->chunkById(self::CHUNK, function ($botUsers) use ($refresher, $clean, &$done, &$failed, &$removed) {
                    foreach ($botUsers as $botUser) {
                        // Копии меню, лежащие поверх актуального, убираем всегда; под ним — только при $clean
                        $refresher->execute($botUser, false, true, $clean) ? $done++ : $failed++;
                        $removed += $refresher->removed;
                    }

                    // Пауза между пачками, чтобы не упереться в лимиты Telegram
                    if ($botUsers->count() === self::CHUNK) {
                        sleep(1);
                    }
                });

            return ['done' => $done, 'failed' => $failed, 'removed' => $removed];
        } finally {
            $lock->release();
        }
    }
}
