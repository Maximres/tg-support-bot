<?php

namespace App\Console\Commands;

use App\Actions\Telegram\DeleteForumTopic;
use App\Models\BotUser;
use Illuminate\Console\Command;

class DeleteTopic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'topic:delete 
                            {--user-id= : ID пользователя в таблице bot_users}
                            {--chat-id= : Chat ID пользователя в Telegram}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Полностью удалить топик клиента без пересоздания';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->option('user-id');
        $chatId = $this->option('chat-id');

        if (!$userId && !$chatId) {
            $this->error('Необходимо указать --user-id или --chat-id');
            return Command::FAILURE;
        }

        $botUser = null;
        if ($userId) {
            $botUser = BotUser::find($userId);
        } elseif ($chatId) {
            $botUser = BotUser::where('chat_id', $chatId)->first();
        }

        if (!$botUser) {
            $this->error('Пользователь не найден');
            return Command::FAILURE;
        }

        return $this->deleteTopicForUser($botUser);
    }

    /**
     * Полностью удалить топик для конкретного пользователя
     *
     * @param BotUser $botUser
     *
     * @return int
     */
    private function deleteTopicForUser(BotUser $botUser): int
    {
        $this->info("Удаление топика для пользователя ID: {$botUser->id}, Chat ID: {$botUser->chat_id}");

        // Обновляем модель для получения актуальных данных
        $botUser->refresh();

        // Edge case: проверяем, что пользователь все еще существует
        if (!$botUser->exists) {
            $this->error('Пользователь был удален из базы данных');
            return Command::FAILURE;
        }

        // Шаг 1: Удаляем существующий топик через Telegram API (если он есть)
        // Используем empty() для проверки null, 0 и пустой строки
        if (!empty($botUser->topic_id)) {
            // Edge case: проверяем, что topic_id является валидным числом
            $topicId = (int)$botUser->topic_id;
            if ($topicId <= 0) {
                $this->warn("⚠ Некорректный topic_id: {$botUser->topic_id}, пропускаем удаление через API");
            } else {
                $this->info("Шаг 1: Удаление топика через Telegram API (topic_id: {$topicId})...");
                try {
                    DeleteForumTopic::execute($botUser);
                    $this->info("✓ Топик удален через Telegram API");
                } catch (\Throwable $e) {
                    $this->warn("⚠ Не удалось удалить топик через API: {$e->getMessage()}");
                    $this->warn("Продолжаем с очисткой topic_id в БД...");
                }
            }
        } else {
            $this->info("Шаг 1: Топик не найден в БД (topic_id пустой), пропускаем удаление через API");
        }

        // Шаг 2: Очищаем topic_id в БД
        // Edge case: обновляем модель перед сохранением на случай изменений
        $botUser->refresh();
        
        // Edge case: проверяем, что пользователь все еще существует перед сохранением
        if (!$botUser->exists) {
            $this->error('Пользователь был удален из базы данных перед сохранением');
            return Command::FAILURE;
        }

        $this->info("Шаг 2: Очистка topic_id в базе данных...");
        $botUser->topic_id = null;
        
        try {
            $botUser->save();
            $this->info("✓ topic_id очищен в БД");
        } catch (\Throwable $e) {
            $this->error("✗ Ошибка при сохранении в БД: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("✅ Топик успешно удален!");
        $this->info("При следующем сообщении от клиента будет автоматически создан новый топик.");

        return Command::SUCCESS;
    }
}

