<?php

namespace App\Console\Commands;

use App\Actions\Telegram\DeleteForumTopic;
use App\Models\BotUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        // Шаг 2: Удаляем связанные данные клиента
        // Edge case: блокируем строку для предотвращения конкурентного удаления
        $botUser->refresh();
        
        // Edge case: проверяем, что пользователь все еще существует перед удалением
        if (!$botUser->exists) {
            $this->error('Пользователь был удален из базы данных перед удалением');
            return Command::FAILURE;
        }

        $this->info("Шаг 2: Удаление связанных данных клиента...");
        
        try {
            DB::beginTransaction();

            // Edge case: блокируем строку для предотвращения конкурентного удаления
            // Используем lockForUpdate() чтобы другие процессы не могли удалить этого пользователя одновременно
            $botUser = BotUser::where('id', $botUser->id)->lockForUpdate()->first();
            
            if (!$botUser) {
                DB::rollBack();
                $this->error('Пользователь был удален другим процессом');
                return Command::FAILURE;
            }

            // Загружаем связи перед удалением
            $botUser->load(['aiCondition', 'externalUser']);

            // Edge case: удаляем AI условие вручную (нет каскадного удаления)
            // Если удаление не удастся, транзакция откатится
            if ($botUser->aiCondition) {
                try {
                    $botUser->aiCondition->delete();
                    $this->info("✓ AI условие удалено");
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->error("✗ Ошибка при удалении AI условия: {$e->getMessage()}");
                    return Command::FAILURE;
                }
            }

            // Edge case: удаляем ExternalUser с проверкой на race condition
            // ExternalUser связан через chat_id = external_user.id
            // Проверяем, используется ли этот external_user другими bot_users
            $externalUser = $botUser->externalUser;
            if ($externalUser) {
                // Edge case: проверяем снова внутри транзакции с блокировкой
                // чтобы избежать race condition - между проверкой и удалением может появиться новый BotUser
                $otherBotUsers = BotUser::where('chat_id', $externalUser->id)
                    ->where('id', '!=', $botUser->id)
                    ->lockForUpdate()
                    ->count();
                
                if ($otherBotUsers === 0) {
                    try {
                        $externalUser->delete();
                        $this->info("✓ ExternalUser удален");
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        $this->error("✗ Ошибка при удалении ExternalUser: {$e->getMessage()}");
                        return Command::FAILURE;
                    }
                } else {
                    $this->warn("⚠ ExternalUser не удален, используется другими пользователями ({$otherBotUsers})");
                }
            }

            // Edge case: сохраняем ID перед удалением для логирования
            // После delete() модель может быть недоступна
            $botUserId = $botUser->id;
            $botUserChatId = $botUser->chat_id;
            $botUserSequentialNumber = $botUser->sequential_number;

            // Удаляем сам BotUser
            // Это автоматически удалит связанные messages и ai_messages благодаря каскадному удалению
            try {
                $botUser->delete();
                $this->info("✓ BotUser удален (ID: {$botUserId}, Chat ID: {$botUserChatId})");
                if ($botUserSequentialNumber) {
                    $this->info("  Порядковый номер {$botUserSequentialNumber} освобожден");
                }
                $this->info("✓ Сообщения и AI сообщения удалены автоматически (каскадное удаление)");
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("✗ Ошибка при удалении BotUser: {$e->getMessage()}");
                return Command::FAILURE;
            }

            DB::commit();
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            // Edge case: обработка специфичных ошибок БД
            if ($e->getCode() === '40001') { // Deadlock
                $this->error("✗ Обнаружен deadlock при удалении. Попробуйте выполнить команду снова.");
            } else {
                $this->error("✗ Ошибка БД при удалении данных клиента: {$e->getMessage()}");
            }
            return Command::FAILURE;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("✗ Ошибка при удалении данных клиента: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("✅ Топик и вся информация о клиенте успешно удалены!");

        return Command::SUCCESS;
    }
}

