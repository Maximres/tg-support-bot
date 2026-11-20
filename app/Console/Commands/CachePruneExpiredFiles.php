<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Файловый кэш Laravel не удаляет просроченные записи сам: он стирает их только при повторном чтении того же ключа.
 * Идентификаторы обновлений Telegram (защита от повторной доставки) каждый раз новые, и без очистки файлов становилось бы всё больше.
 */
class CachePruneExpiredFiles extends Command
{
    protected $signature = 'cache:prune-expired-files';

    protected $description = 'Удаляет просроченные записи файлового кэша';

    /**
     * @return int
     */
    public function handle(): int
    {
        $path = (string)config('cache.stores.file.path');

        if (!is_dir($path)) {
            return Command::SUCCESS;
        }

        $removed = 0;
        $now = time();

        foreach ((new Finder())->files()->in($path) as $file) {
            // Первые 10 символов файла — время, до которого запись действует
            $handle = @fopen($file->getPathname(), 'r');

            if ($handle === false) {
                continue;
            }

            $expire = (string)fread($handle, 10);
            fclose($handle);

            // Пустой или нечисловой заголовок — не запись кэша Laravel, не трогаем
            if (ctype_digit($expire) && (int)$expire <= $now && @unlink($file->getPathname())) {
                $removed++;
            }
        }

        $this->info("Удалено просроченных записей: {$removed}");

        return Command::SUCCESS;
    }
}
