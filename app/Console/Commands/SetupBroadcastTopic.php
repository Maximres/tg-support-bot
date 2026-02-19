<?php

namespace App\Console\Commands;

use App\Actions\Telegram\CheckTopicExists;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\TelegramBot\TelegramMethods;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SetupBroadcastTopic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'broadcast:setup 
                            {--name= : Название топика (по умолчанию: 📢 Массовая рассылка)}
                            {--force : Пересоздать топик, если уже существует}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создать топик массовых рассылок и настроить конфигурацию';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $topicName = $this->option('name') ?: '📢 Массовая рассылка';
        $force = $this->option('force');

        $this->info("Настройка топика массовых рассылок: {$topicName}");

        // Проверяем существующий топик
        $existingTopicId = config('traffic_source.settings.telegram.broadcast_topic_id');
        
        if ($existingTopicId && !$force) {
            // Проверяем существование топика
            if (CheckTopicExists::execute((int)$existingTopicId)) {
                $this->warn("Топик массовых рассылок уже настроен (ID: {$existingTopicId})");
                $this->info("Используйте --force для пересоздания топика");
                return Command::SUCCESS;
            } else {
                $this->warn("Топик с ID {$existingTopicId} не существует, создаем новый");
            }
        }

        // Удаляем старый топик, если нужно
        if ($existingTopicId && $force) {
            $this->info("Удаление старого топика (ID: {$existingTopicId})...");
            try {
                SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                    'methodQuery' => 'deleteForumTopic',
                    'chat_id' => config('traffic_source.settings.telegram.group_id'),
                    'message_thread_id' => $existingTopicId,
                ]));
                $this->info("✓ Задача удаления топика поставлена в очередь");
            } catch (\Throwable $e) {
                $this->warn("⚠ Не удалось удалить топик: {$e->getMessage()}");
            }
        }

        // Создаем новый топик
        $this->info("Создание нового топика...");
        
        $telegramMethods = new TelegramMethods();
        $response = $telegramMethods->sendQueryTelegram('createForumTopic', [
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'name' => $topicName,
        ]);

        if ($response->ok === true) {
            $topicId = $response->message_thread_id;
            $this->info("✓ Топик успешно создан (ID: {$topicId})");

            // Сохраняем topic_id в .env
            if ($this->updateEnvFile($topicId)) {
                $this->info("✓ Конфигурация обновлена в .env");
                $this->newLine();
                $this->info("✅ Топик массовых рассылок успешно настроен!");
                $this->info("Topic ID: {$topicId}");
                $this->info("Название: {$topicName}");
                $this->newLine();
                $this->comment("Примечание: Выполните 'php artisan config:cache' для применения изменений");
                return Command::SUCCESS;
            } else {
                $this->error("✗ Не удалось обновить .env файл");
                $this->warn("Вручную добавьте в .env: TELEGRAM_BROADCAST_TOPIC_ID={$topicId}");
                return Command::FAILURE;
            }
        }

        // Обработка ошибок
        if ($response->response_code === 429) {
            $retryAfter = $response->parameters->retry_after ?? 3;
            $this->error("429 Too Many Requests. Повторите через {$retryAfter} секунд");
            return Command::FAILURE;
        }

        if ($response->response_code === 400) {
            $this->error("400 Bad Request: " . ($response->description ?? 'Неизвестная ошибка'));
            $this->comment("Проверьте права бота на создание топиков в группе");
            return Command::FAILURE;
        }

        if ($response->response_code === 403) {
            $this->error("403 Forbidden: У бота нет прав на создание топиков");
            return Command::FAILURE;
        }

        $this->error("Неизвестная ошибка: " . json_encode($response->rawData ?? []));
        return Command::FAILURE;
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
            Log::error('SetupBroadcastTopic: ошибка обновления .env', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

