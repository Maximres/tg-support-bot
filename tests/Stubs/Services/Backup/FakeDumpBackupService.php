<?php

namespace Tests\Stubs\Services\Backup;

use App\Services\Backup\DatabaseBackupService;
use RuntimeException;

/**
 * Сервис бэкапа без настоящего pg_dump: в тестах БД — SQLite
 */
class FakeDumpBackupService extends DatabaseBackupService
{
    public int $dumps = 0;

    public bool $fail = false;

    protected function dump(string $path): void
    {
        $this->dumps++;

        if ($this->fail) {
            throw new RuntimeException('pg_dump: boom');
        }

        // Несжимаемые данные, чтобы дамп прошёл проверку на минимальный размер
        $gz = gzopen($path, 'wb9');
        gzwrite($gz, random_bytes(4096));
        gzclose($gz);
    }
}
