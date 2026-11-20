<?php

namespace App\Console\Commands;

use App\Actions\Telegram\RefreshAllAccessMenus;
use App\TelegramBot\TelegramMethods;
use Illuminate\Console\Command;

class TelegramRefreshAccessMenus extends Command
{
    protected $signature = 'telegram:refresh-access-menus
                            {--clean : Очистить закрепы и там, где сверху актуальное меню (под ним могут быть копии); в чате появится строка «закреплено»}
                            {--report-chat= : Прислать отчёт в этот чат (его задаёт команда /refresh_menus)}
                            {--report-thread= : Тема чата для отчёта}';

    protected $description = 'Обновляет закреплённое меню у всех клиентов под их текущий доступ и актуальную раскладку кнопок';

    /**
     * @return int
     */
    public function handle(): int
    {
        $clean = (bool)$this->option('clean');
        $result = (new RefreshAllAccessMenus())->execute($clean);

        if ($chatId = $this->option('report-chat')) {
            TelegramMethods::sendQueryTelegram('sendMessage', array_filter([
                'chat_id' => $chatId,
                'message_thread_id' => $this->option('report-thread') ?: null,
                'text' => RefreshAllAccessMenus::summary($result, $clean),
                'parse_mode' => 'html',
            ], fn ($value) => $value !== null));
        }

        if ($result === null) {
            $this->warn('Обновление меню уже идёт.');

            return Command::FAILURE;
        }

        $this->info("Меню обновлено: {$result['done']}, не удалось: {$result['failed']}, очищены закрепы у клиентов: {$result['removed']}");

        return Command::SUCCESS;
    }
}
