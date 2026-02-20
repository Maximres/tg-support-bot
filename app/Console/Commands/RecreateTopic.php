<?php

namespace App\Console\Commands;

use App\Actions\Telegram\DeleteForumTopic;
use App\Jobs\TopicCreateJob;
use App\Models\BotUser;
use Illuminate\Console\Command;

class RecreateTopic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'topic:recreate 
                            {--user-id= : ID пользователя в таблице bot_users}
                            {--chat-id= : Chat ID пользователя в Telegram}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удалить существующий топик и создать новый с нуля';

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

        return $this->recreateTopicForUser($botUser);
    }

    /**
     * Удалить и пересоздать топик для конкретного пользователя
     *
     * @param BotUser $botUser
     *
     * @return int
     */
    private function recreateTopicForUser(BotUser $botUser): int
    {
        $this->info("Пересоздание топика для пользователя ID: {$botUser->id}, Chat ID: {$botUser->chat_id}");

        // Шаг 1: Удаляем существующий топик через Telegram API (если он есть)
        if ($botUser->topic_id) {
            $this->info("Шаг 1: Удаление существующего топика (topic_id: {$botUser->topic_id})...");
            try {
                DeleteForumTopic::execute($botUser);
                $this->info("✓ Топик удален через Telegram API");
            } catch (\Throwable $e) {
                $this->warn("⚠ Не удалось удалить топик через API: {$e->getMessage()}");
                $this->warn("Продолжаем с очисткой topic_id в БД...");
            }
        } else {
            $this->info("Шаг 1: Топик не найден в БД (topic_id пустой), пропускаем удаление");
        }

        // Шаг 2: Очищаем topic_id в БД
        $this->info("Шаг 2: Очистка topic_id в базе данных...");
        $botUser->topic_id = null;
        $botUser->save();
        $this->info("✓ topic_id очищен в БД");

        // Шаг 3: Создаем новый топик
        $this->info("Шаг 3: Создание нового топика...");
        TopicCreateJob::dispatch($botUser->id);
        $this->info("✓ Задача создания топика поставлена в очередь");

        $this->newLine();
        $this->info("✅ Топик успешно пересоздан!");
        $this->info("Топик будет создан в ближайшее время через очередь.");
        $this->info("Проверьте логи или отправьте сообщение пользователю для проверки.");

        return Command::SUCCESS;
    }
}


