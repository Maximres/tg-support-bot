<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Делает бэкап БД прямо сейчас и отправляет зашифрованную копию в чат бэкапов';

    /**
     * @param DatabaseBackupService $backup
     *
     * @return int
     */
    public function handle(DatabaseBackupService $backup): int
    {
        $result = $backup->run();

        if (!$result['ok']) {
            $this->error('Бэкап не удался: ' . $result['error']);

            return Command::FAILURE;
        }

        $this->info('Бэкап готов, размер дампа: ' . $result['size'] . ' байт');

        return Command::SUCCESS;
    }
}
