<?php

use App\Services\Backup\DatabaseBackupService;
use App\Services\Rental\RotationNotifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Сводное уведомление доверенным о ротации кодов и ссылок (после пары минут без новых замен)
Schedule::call(fn () => app(RotationNotifier::class)->flushIfQuiet())
    ->name('rotation:flush')
    ->everyMinute()
    ->withoutOverlapping();

// Ежедневный бэкап БД: включение и время задаются админами командами /backup_on, /backup_time
Schedule::call(fn () => app(DatabaseBackupService::class)->runIfDue())
    ->name('backup:daily-check')
    ->everyMinute()
    ->withoutOverlapping();
