<?php

namespace App\Console\Commands;

use App\Actions\Telegram\RefreshAllAccessMenus;
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
        $result = (new RefreshAllAccessMenus())->execute();

        if ($result === null) {
            $this->warn('Обновление меню уже идёт.');

            return Command::FAILURE;
        }

        $this->info("Меню обновлено: {$result['done']}, не удалось: {$result['failed']}");

        return Command::SUCCESS;
    }
}
