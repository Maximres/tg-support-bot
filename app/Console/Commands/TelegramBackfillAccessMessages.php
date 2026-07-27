<?php

namespace App\Console\Commands;

use App\Actions\Telegram\SendAccessMessage;
use App\Models\BotUser;
use Illuminate\Console\Command;

class TelegramBackfillAccessMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:backfill-access-messages {--dry-run : Показать, кому будет отправлено сообщение, без реальной отправки}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Отправляет и закрепляет сообщение с кнопками доступа к кодам сотрудникам, у которых его ещё нет';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $sendAccessMessage = new SendAccessMessage();
        $total = 0;

        BotUser::query()
            ->where('platform', 'telegram')
            ->where('is_banned', false)
            ->whereNotNull('chat_id')
            ->whereNotNull('topic_id')
            ->whereNull('access_message_id')
            ->chunkById(20, function ($botUsers) use ($sendAccessMessage, $dryRun, &$total) {
                foreach ($botUsers as $botUser) {
                    $total++;

                    if ($dryRun) {
                        $this->line("Будет отправлено: bot_user_id={$botUser->id}, chat_id={$botUser->chat_id}");
                        continue;
                    }

                    $sendAccessMessage->execute($botUser);
                }

                // Небольшая пауза между чанками, чтобы не упереться в rate limit Telegram
                if (!$dryRun) {
                    sleep(1);
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '') . "Обработано сотрудников: {$total}");

        return Command::SUCCESS;
    }
}
