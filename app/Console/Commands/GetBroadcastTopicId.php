<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GetBroadcastTopicId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'broadcast:get-topic-id 
                            {--name= : Название топика для поиска}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получить topic_id существующего топика массовых рассылок';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("Получение topic_id топика массовых рассылок");
        $this->newLine();

        // Telegram Bot API не имеет прямого метода для получения списка топиков
        // Поэтому используем альтернативный подход
        $this->comment("Telegram Bot API не поддерживает получение списка топиков напрямую.");
        $this->comment("Для получения topic_id выполните следующие шаги:");
        $this->newLine();

        $this->info("Способ 1: Из сообщения в топике");
        $this->comment("  1. Отправьте любое сообщение в топик массовых рассылок");
        $this->comment("  2. В логах приложения найдите 'message_thread_id' из webhook");
        $this->comment("  3. Это и есть topic_id для конфига");
        $this->newLine();

        $this->info("Способ 2: Временное логирование");
        $this->comment("  1. Временно добавьте логирование в TelegramBotController");
        $this->comment("  2. Отправьте сообщение в топик");
        $this->comment("  3. Найдите message_thread_id в логах");
        $this->newLine();

        // Предлагаем ввести topic_id вручную
        $topicId = $this->ask('Введите topic_id (message_thread_id) топика массовых рассылок');

        if (empty($topicId) || !is_numeric($topicId)) {
            $this->error("Некорректный topic_id");
            return Command::FAILURE;
        }

        $topicId = (int)$topicId;

        // Сохраняем в .env
        if ($this->updateEnvFile($topicId)) {
            $this->info("✓ Конфигурация обновлена в .env");
            $this->newLine();
            $this->info("✅ Topic ID успешно сохранен: {$topicId}");
            $this->newLine();
            $this->comment("Примечание: Выполните 'php artisan config:cache' для применения изменений");
            return Command::SUCCESS;
        } else {
            $this->error("✗ Не удалось обновить .env файл");
            $this->warn("Вручную добавьте в .env: TELEGRAM_BROADCAST_TOPIC_ID={$topicId}");
            return Command::FAILURE;
        }
    }

    /**
     * Обновляет .env файл с новым topic_id
     *
     * @param int $topicId
     *
     * @return bool
     */
    protected function updateEnvFile(int $topicId): bool
    {
        try {
            $envPath = base_path('.env');
            
            if (!File::exists($envPath)) {
                $this->warn("Файл .env не найден, создаем новый");
                File::put($envPath, "TELEGRAM_BROADCAST_TOPIC_ID={$topicId}\n");
                return true;
            }

            $envContent = File::get($envPath);
            $key = 'TELEGRAM_BROADCAST_TOPIC_ID';
            
            // Проверяем, существует ли уже эта переменная
            if (preg_match("/^{$key}=.*$/m", $envContent)) {
                // Заменяем существующее значение
                $envContent = preg_replace(
                    "/^{$key}=.*$/m",
                    "{$key}={$topicId}",
                    $envContent
                );
            } else {
                // Добавляем новую переменную в конец файла
                $envContent .= "\n{$key}={$topicId}\n";
            }

            File::put($envPath, $envContent);
            return true;
        } catch (\Throwable $e) {
            Log::error('GetBroadcastTopicId: ошибка обновления .env', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

