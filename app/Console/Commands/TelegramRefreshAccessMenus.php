<?php

namespace App\Console\Commands;

use App\Actions\Telegram\RefreshAllAccessMenus;
use Illuminate\Console\Command;

class TelegramRefreshAccessMenus extends Command
{
    protected $signature = 'telegram:refresh-access-menus
                            {--clean : Очистить закрепы и там, где сверху актуальное меню (под ним могут быть копии); в чате появится строка «закреплено»}';

    protected $description = 'Обновляет закреплённое меню у всех клиентов под их текущий доступ и актуальную раскладку кнопок';

    /**
     * @return int
     */
    public function handle(): int
    {
        $result = (new RefreshAllAccessMenus())->execute((bool)$this->option('clean'));

        if ($result === null) {
            $this->warn('Обновление меню уже идёт.');

            return Command::FAILURE;
        }

        $this->info("Меню обновлено: {$result['done']}, не удалось: {$result['failed']}, очищены закрепы у клиентов: {$result['removed']}");

        return Command::SUCCESS;
    }
}
