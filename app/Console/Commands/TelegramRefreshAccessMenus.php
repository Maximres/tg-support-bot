<?php

namespace App\Console\Commands;

use App\Actions\Telegram\RefreshAccessMessage;
use App\Models\BotUser;
use Illuminate\Console\Command;

class TelegramRefreshAccessMenus extends Command
{
    protected $signature = 'telegram:refresh-access-menus';

    protected $description = 'Обновляет закреплённое меню у всех клиентов под их текущий доступ и актуальную раскладку кнопок';

    /**
     * @return int
     */
    public function handle(): int
    {
        $refresher = new RefreshAccessMessage();
        $done = 0;
        $failed = 0;

        BotUser::query()
            ->where('platform', 'telegram')
            ->whereNotNull('chat_id')
            ->whereNotNull('access_message_id')
            ->chunkById(20, function ($botUsers) use ($refresher, &$done, &$failed) {
                foreach ($botUsers as $botUser) {
                    $refresher->execute($botUser) ? $done++ : $failed++;
                }

                // Небольшая пауза между пачками, чтобы не упереться в rate limit Telegram
                sleep(1);
            });

        $this->info("Меню обновлено: {$done}, не удалось: {$failed}");

        return Command::SUCCESS;
    }
}
