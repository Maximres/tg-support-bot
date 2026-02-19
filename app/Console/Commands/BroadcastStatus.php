<?php

namespace App\Console\Commands;

use App\Actions\Telegram\CheckTopicExists;
use App\Models\BotUser;
use Illuminate\Console\Command;

class BroadcastStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'broadcast:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Показать статус настройки массовой рассылки';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("Статус настройки массовой рассылки");
        $this->newLine();

        // Проверяем конфигурацию
        $topicId = config('traffic_source.settings.telegram.broadcast_topic_id');
        $envValue = env('TELEGRAM_BROADCAST_TOPIC_ID');
        
        $this->table(
            ['Параметр', 'Значение'],
            [
                ['Конфигурация (config)', $topicId ? "✓ Настроена (ID: {$topicId}, тип: " . gettype($topicId) . ")" : "✗ Не настроена"],
                ['.env значение', $envValue ? "✓ Установлено ({$envValue})" : "✗ Не установлено"],
            ]
        );
        
        if ($topicId !== $envValue && $envValue) {
            $this->warn("⚠ Внимание: значение в config не совпадает с .env!");
            $this->comment("Выполните: php artisan config:clear");
        }

        if (!$topicId) {
            $this->newLine();
            $this->warn("Топик массовых рассылок не настроен!");
            $this->comment("Используйте 'php artisan broadcast:setup' для создания топика");
            return Command::SUCCESS;
        }

        // Проверяем существование топика
        $this->info("Проверка существования топика...");
        $topicExists = CheckTopicExists::execute((int)$topicId);
        
        $this->table(
            ['Параметр', 'Значение'],
            [
                ['Topic ID', $topicId],
                ['Статус топика', $topicExists ? '✓ Существует' : '✗ Не существует'],
            ]
        );

        // Получаем количество получателей
        $usersCount = BotUser::where('is_banned', false)
            ->whereNotNull('chat_id')
            ->where('platform', 'telegram')
            ->count();

        $this->table(
            ['Параметр', 'Значение'],
            [
                ['Количество получателей', $usersCount],
            ]
        );

        $this->newLine();

        if (!$topicExists) {
            $this->warn("⚠ Топик не существует! Используйте 'php artisan broadcast:setup --force' для пересоздания");
        } else {
            $this->info("✅ Массовая рассылка настроена и готова к работе");
        }

        return Command::SUCCESS;
    }
}

