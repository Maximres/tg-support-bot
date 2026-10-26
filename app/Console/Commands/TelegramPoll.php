<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Получение обновлений тестового бота через long polling (getUpdates) и пересылка их в локальный
 * обработчик webhook: для тестового стенда без туннеля (ngrok/cloudflared) — нужен только исходящий доступ к Telegram.
 *
 * На проде не запускается; не снимает webhook, если он задан (боевой бот работает по webhook).
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll
                            {--once : Сделать один запрос getUpdates и выйти}
                            {--timeout=25 : Таймаут long polling, секунд}
                            {--url= : Адрес обработчика (по умолчанию APP_URL + /api/telegram/bot)}
                            {--force : Снять уже заданный webhook (только для тестового бота!)}';

    protected $description = 'Тестовый стенд: получает обновления Telegram через getUpdates и отправляет их в обработчик бота';

    /**
     * @return int
     */
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('telegram:poll не предназначена для боевого окружения (APP_ENV=production).');

            return Command::FAILURE;
        }

        $token = (string)config('traffic_source.settings.telegram.token');
        $secret = (string)config('traffic_source.settings.telegram.secret_key');

        if ($token === '') {
            $this->error('TELEGRAM_TOKEN не задан.');

            return Command::FAILURE;
        }

        $target = (string)($this->option('url') ?: rtrim((string)config('app.url'), '/') . '/api/telegram/bot');
        $timeout = max(0, (int)$this->option('timeout'));

        if (!$this->prepareWebhook($token)) {
            return Command::FAILURE;
        }

        $this->info("Получаю обновления, передаю в {$target}");

        $offset = $this->readOffset();

        do {
            $updates = $this->getUpdates($token, $offset, $timeout);

            foreach ($updates as $update) {
                $this->forward($target, $secret, $update);
                $offset = (int)$update['update_id'] + 1;
                $this->saveOffset($offset);
            }
        } while (!$this->option('once'));

        return Command::SUCCESS;
    }

    /**
     * Webhook и getUpdates взаимоисключающие. Заданный webhook (боевой бот!) без --force не трогаем.
     *
     * @param string $token
     *
     * @return bool
     */
    private function prepareWebhook(string $token): bool
    {
        $info = $this->request($token, 'getWebhookInfo', [], 30);

        if (!($info['ok'] ?? false)) {
            $this->error('Не удалось связаться с Telegram: ' . ($info['description'] ?? 'нет ответа'));

            return false;
        }

        $webhookUrl = (string)($info['result']['url'] ?? '');

        if ($webhookUrl === '') {
            return true;
        }

        if (!$this->option('force')) {
            $this->error("У бота задан webhook ({$webhookUrl}). Это похоже на боевого бота — polling остановлен. "
                . 'Для тестового бота повторите с --force, чтобы снять webhook.');

            return false;
        }

        $this->warn("Снимаю webhook {$webhookUrl} (--force)");
        $this->request($token, 'deleteWebhook', ['drop_pending_updates' => false], 30);

        return true;
    }

    /**
     * @param string $token
     * @param int    $offset
     * @param int    $timeout
     *
     * @return array
     */
    private function getUpdates(string $token, int $offset, int $timeout): array
    {
        $params = ['timeout' => $timeout];
        if ($offset > 0) {
            $params['offset'] = $offset;
        }

        $response = $this->request($token, 'getUpdates', $params, $timeout + 15);

        if (!($response['ok'] ?? false)) {
            $this->warn('getUpdates: ' . ($response['description'] ?? 'ошибка сети') . ' — повтор через 3 с');
            if (!$this->option('once')) {
                sleep(3);
            }

            return [];
        }

        return $response['result'] ?? [];
    }

    /**
     * @param string $target
     * @param string $secret
     * @param array  $update
     *
     * @return void
     */
    private function forward(string $target, string $secret, array $update): void
    {
        try {
            $response = Http::timeout(120)
                ->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])
                ->post($target, $update);

            $this->line(sprintf('update %d -> HTTP %d', $update['update_id'] ?? 0, $response->status()));
        } catch (\Throwable $e) {
            $this->error('Не удалось передать обновление ' . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * @param string $token
     * @param string $method
     * @param array  $params
     * @param int    $timeout
     *
     * @return array
     */
    private function request(string $token, string $method, array $params, int $timeout): array
    {
        try {
            $client = Http::timeout($timeout);

            if (config('traffic_source.settings.telegram.force_ipv4')) {
                $client = $client->withOptions(['force_ip_resolve' => 'v4']);
            }

            return (array)$client->post("https://api.telegram.org/bot{$token}/{$method}", $params)->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => preg_replace('~bot\d+:[\w-]+~', 'bot***', $e->getMessage())];
        }
    }

    /**
     * @return string
     */
    private function offsetPath(): string
    {
        return storage_path('app/telegram_poll_offset');
    }

    /**
     * @return int
     */
    private function readOffset(): int
    {
        $path = $this->offsetPath();

        return File::exists($path) ? (int)trim((string)File::get($path)) : 0;
    }

    /**
     * @param int $offset
     *
     * @return void
     */
    private function saveOffset(int $offset): void
    {
        File::ensureDirectoryExists(dirname($this->offsetPath()));
        File::put($this->offsetPath(), (string)$offset);
    }
}
