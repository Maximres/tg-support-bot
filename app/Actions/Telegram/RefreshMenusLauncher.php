<?php

namespace App\Actions\Telegram;

use Symfony\Component\Process\Process;

/**
 * Запускает обновление меню у всех клиентов отдельным процессом и сразу возвращает управление.
 *
 * Обход всех клиентов занимает минуты, а Telegram ждёт ответ вебхука около минуты и повторно присылает то же
 * обновление: пока команда выполнялась прямо в запросе, бот зависал и обновлял меню снова и снова.
 */
class RefreshMenusLauncher
{
    /**
     * @param bool        $clean
     * @param int|string  $reportChatId   чат, куда процесс пришлёт отчёт
     * @param int|null    $reportThreadId тема, в которую писать отчёт
     *
     * @return void
     */
    public function start(bool $clean, int|string $reportChatId, ?int $reportThreadId): void
    {
        $command = [
            'nohup', 'php', base_path('artisan'), 'telegram:refresh-access-menus',
            '--report-chat=' . $reportChatId,
        ];

        if ($reportThreadId) {
            $command[] = '--report-thread=' . $reportThreadId;
        }

        if ($clean) {
            $command[] = '--clean';
        }

        // Оболочка с «&» завершается сразу, а процесс-потомок продолжает работать после ответа на запрос
        $process = Process::fromShellCommandline(
            implode(' ', array_map('escapeshellarg', $command)) . ' > /dev/null 2>&1 &',
            base_path()
        );
        $process->setTimeout(10);
        $process->run();
    }
}
