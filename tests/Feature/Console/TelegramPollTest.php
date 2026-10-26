<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Тестовый стенд: получение обновлений через getUpdates
 */
class TelegramPollTest extends TestCase
{
    private string $offsetFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offsetFile = storage_path('app/telegram_poll_offset');
        File::delete($this->offsetFile);

        config([
            'traffic_source.settings.telegram.token' => '111:TESTTOKEN',
            'traffic_source.settings.telegram.secret_key' => 'test-secret',
            'app.url' => 'http://app.test',
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->offsetFile);

        parent::tearDown();
    }

    private function fakeTelegram(string $webhookUrl = '', array $updates = []): void
    {
        Http::fake([
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $webhookUrl]]),
            '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
            'http://app.test/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->fakeTelegram();

        $this->artisan('telegram:poll', ['--once' => true])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_refuses_when_a_webhook_is_set_without_force(): void
    {
        $this->fakeTelegram('https://prod.example.com/api/telegram/bot');

        $this->artisan('telegram:poll', ['--once' => true, '--timeout' => 0])->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'deleteWebhook'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'getUpdates'));
    }

    public function test_force_removes_the_webhook_and_polls(): void
    {
        $this->fakeTelegram('https://old.example.com/api/telegram/bot');

        $this->artisan('telegram:poll', ['--once' => true, '--timeout' => 0, '--force' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'deleteWebhook'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getUpdates'));
    }

    public function test_updates_are_forwarded_with_the_secret_and_the_offset_is_saved(): void
    {
        $this->fakeTelegram('', [
            ['update_id' => 100, 'message' => ['message_id' => 1, 'text' => 'hello']],
            ['update_id' => 101, 'message' => ['message_id' => 2, 'text' => 'again']],
        ]);

        $this->artisan('telegram:poll', ['--once' => true, '--timeout' => 0])->assertSuccessful();

        $forwarded = [];
        foreach (Http::recorded()->all() as [$request]) {
            if ($request->url() === 'http://app.test/api/telegram/bot') {
                $this->assertSame('test-secret', $request->header('X-Telegram-Bot-Api-Secret-Token')[0]);
                $forwarded[] = $request['update_id'];
            }
        }

        $this->assertSame([100, 101], $forwarded);
        $this->assertSame('102', trim(File::get($this->offsetFile)));
    }

    public function test_next_run_continues_from_the_saved_offset(): void
    {
        File::put($this->offsetFile, '102');
        $this->fakeTelegram();

        $this->artisan('telegram:poll', ['--once' => true, '--timeout' => 0])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getUpdates') && $r['offset'] === 102);
    }
}
