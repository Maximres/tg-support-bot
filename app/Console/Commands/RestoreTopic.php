<?php

namespace App\Console\Commands;

use App\Jobs\TopicCreateJob;
use App\Models\BotUser;
use Illuminate\Console\Command;

class RestoreTopic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'topic:restore 
                            {--user-id= : ID пользователя в таблице bot_users}
                            {--chat-id= : Chat ID пользователя в Telegram}
                            {--all : Восстановить все топики с пустым topic_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Восстановить удаленный топик для пользователя';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->restoreAllTopics();
        }

        $userId = $this->option('user-id');
        $chatId = $this->option('chat-id');

        if (!$userId && !$chatId) {
            $this->error('Необходимо указать --user-id или --chat-id, либо использовать --all');
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

        return $this->restoreTopicForUser($botUser);
    }

    /**
     * Восстановить топик для конкретного пользователя
     *
     * @param BotUser $botUser
     *
     * @return int
     */
    private function restoreTopicForUser(BotUser $botUser): int
    {
        $this->info("Восстановление топика для пользователя ID: {$botUser->id}, Chat ID: {$botUser->chat_id}");

        // Очищаем topic_id если он есть (на случай если он указывает на удаленный топик)
        if ($botUser->topic_id) {
            $this->warn("Текущий topic_id: {$botUser->topic_id} будет очищен");
            $botUser->topic_id = null;
            $botUser->save();
        }

        // Создаем новый топик
        TopicCreateJob::dispatch($botUser->id);

        $this->info("Задача создания топика поставлена в очередь. Топик будет создан в ближайшее время.");
        $this->info("Проверьте логи или отправьте сообщение пользователю для проверки.");

        return Command::SUCCESS;
    }

    /**
     * Восстановить все топики с пустым topic_id
     *
     * @return int
     */
    private function restoreAllTopics(): int
    {
        $this->info('Поиск пользователей без топиков...');

        $botUsers = BotUser::whereNull('topic_id')
            ->orWhere('topic_id', 0)
            ->get();

        if ($botUsers->isEmpty()) {
            $this->info('Пользователи без топиков не найдены');
            return Command::SUCCESS;
        }

        $this->info("Найдено пользователей без топиков: {$botUsers->count()}");

        if (!$this->confirm('Продолжить восстановление всех топиков?', true)) {
            $this->info('Операция отменена');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($botUsers->count());
        $bar->start();

        $restored = 0;
        foreach ($botUsers as $botUser) {
            try {
                TopicCreateJob::dispatch($botUser->id);
                $restored++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Ошибка при восстановлении топика для пользователя ID {$botUser->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Восстановлено топиков: {$restored} из {$botUsers->count()}");

        return Command::SUCCESS;
    }
}

