<?php

namespace App\Console\Commands;

use App\TelegramBot\TelegramMethods;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Следит за здоровьем вебхука: копится очередь обновлений или Telegram получает ошибки от сервера.
 * Запускается планировщиком; о проблеме пишет в группу поддержки, не чаще раза в час.
 */
class TelegramCheckWebhook extends Command
{
    protected $signature = 'telegram:check-webhook {--no-alert : Только показать состояние, не писать в группу}';

    protected $description = 'Проверяет вебхук Telegram и сообщает в группу, если очередь обновлений копится или есть ошибки доставки';

    private const ALERT_LOCK = 'webhook_monitor_alert';

    /**
     * @return int
     */
    public function handle(): int
    {
        $answer = TelegramMethods::sendQueryTelegram('getWebhookInfo', []);
        $info = $answer->rawData['result'] ?? null;

        if (!$answer->ok || !is_array($info)) {
            $this->error('Не удалось получить состояние вебхука.');

            return Command::FAILURE;
        }

        // Вебхук не задан (тестовый стенд на long polling) — проверять нечего
        if (empty($info['url'])) {
            $this->line('Вебхук не задан, проверка пропущена.');

            return Command::SUCCESS;
        }

        $problems = $this->problems($info);

        if ($problems === []) {
            $this->info('Вебхук в порядке, в очереди: ' . (int)($info['pending_update_count'] ?? 0));

            return Command::SUCCESS;
        }

        $this->warn(implode("\n", $problems));

        if (!$this->option('no-alert')) {
            $this->notifyGroup($problems);
        }

        return Command::FAILURE;
    }

    /**
     * @param array $info Ответ getWebhookInfo
     *
     * @return string[]
     */
    private function problems(array $info): array
    {
        $problems = [];

        $pending = (int)($info['pending_update_count'] ?? 0);
        $limit = (int)config('rental.webhook_monitor.pending_limit');

        if ($pending >= $limit) {
            $problems[] = __('messages.webhook_monitor.pending', ['count' => $pending]);
        }

        $errorDate = (int)($info['last_error_date'] ?? 0);
        $recent = time() - $errorDate <= (int)config('rental.webhook_monitor.error_window_minutes') * 60;

        if ($errorDate > 0 && $recent && !empty($info['last_error_message'])) {
            $problems[] = __('messages.webhook_monitor.error', [
                'message' => htmlspecialchars((string)$info['last_error_message']),
                'time' => date('H:i', $errorDate),
            ]);
        }

        return $problems;
    }

    /**
     * @param string[] $problems
     *
     * @return void
     */
    private function notifyGroup(array $problems): void
    {
        $groupId = config('traffic_source.settings.telegram.group_id');

        // Раз в час: пока проблема не устранена, группу не засыпаем одинаковыми сообщениями
        if (empty($groupId) || !Cache::add(self::ALERT_LOCK, 1, now()->addHour())) {
            return;
        }

        $answer = TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $groupId,
            'text' => __('messages.webhook_monitor.title') . "\n\n" . implode("\n", $problems),
            'parse_mode' => 'html',
        ]);

        // Не доставили — не блокируем следующую попытку на час
        if (!$answer->ok) {
            Cache::forget(self::ALERT_LOCK);
        }
    }
}
