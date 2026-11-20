<?php

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ежедневный бэкап БД: включение и время задаются админами командами /backup_on, /backup_time
Schedule::call(fn () => app(DatabaseBackupService::class)->runIfDue())
    ->name('backup:daily-check')
    ->everyMinute()
    ->withoutOverlapping();

// Проверка вебхука: копится очередь или Telegram получает ошибки от сервера — об этом пишем в группу
Schedule::command('telegram:check-webhook')
    ->name('telegram:check-webhook')
    ->everyFiveMinutes()
    // Блокировка живёт 10 минут: убитый процесс не должен выключать проверку на сутки (по умолчанию 1440)
    ->withoutOverlapping(10);

// Файловый кэш сам просроченные записи не стирает, а идентификаторы обновлений Telegram в нём каждый раз новые
Schedule::command('cache:prune-expired-files')
    ->dailyAt('04:30')
    ->withoutOverlapping(30);
