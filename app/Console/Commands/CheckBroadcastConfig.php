<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckBroadcastConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'broadcast:check-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверить конфигурацию массовой рассылки (для отладки)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("Проверка конфигурации массовой рассылки");
        $this->newLine();

        $configValue = config('traffic_source.settings.telegram.broadcast_topic_id');
        $envValue = env('TELEGRAM_BROADCAST_TOPIC_ID');

        $this->table(
            ['Параметр', 'Значение', 'Тип'],
            [
                ['config() значение', $configValue ?? 'null', gettype($configValue)],
                ['env() значение', $envValue ?? 'null', gettype($envValue)],
            ]
        );

        $this->newLine();

        if ($configValue === null) {
            $this->error("✗ config() возвращает null!");
            $this->comment("Выполните: php artisan config:clear");
        } else {
            $this->info("✓ config() возвращает значение: {$configValue}");
        }

        if ($envValue === null) {
            $this->warn("⚠ env() возвращает null (это нормально, если используется config cache)");
        } else {
            $this->info("✓ env() возвращает значение: {$envValue}");
        }

        if ($configValue && $envValue && (string)$configValue !== (string)$envValue) {
            $this->warn("⚠ Значения не совпадают!");
            $this->comment("config: {$configValue}, env: {$envValue}");
        }

        // Проверяем, что значение можно использовать для сравнения
        if ($configValue) {
            $this->newLine();
            $this->info("Тест сравнения:");
            $testIds = [176, 178, '176', '178'];
            foreach ($testIds as $testId) {
                $matches = (int)$testId === (int)$configValue;
                $this->line("  (int){$testId} === (int){$configValue}: " . ($matches ? '✓ СОВПАДАЕТ' : '✗ не совпадает'));
            }
        }

        return Command::SUCCESS;
    }
}

