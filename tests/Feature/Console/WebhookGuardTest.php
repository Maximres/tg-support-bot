<?php

namespace Tests\Feature\Console;

use App\Middleware\SkipDuplicateTelegramUpdate;
use App\Middleware\TelegramQuery;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Защита от зависаний вебхука: повторная доставка обновления и проверка состояния вебхука
 */
class WebhookGuardTest extends TestCase
{
    private const GROUP_ID = -1001234567890;

    private array $webhookInfo = [];

    private bool $telegramDown = false;

    private bool $sendFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'traffic_source.settings.telegram.group_id' => self::GROUP_ID,
            'traffic_source.settings.telegram.secret_key' => 'secret',
        ]);

        Route::post('guard-test', fn () => response()->json(['handled' => true]))->middleware(SkipDuplicateTelegramUpdate::class);

        Http::fake(function (HttpRequest $request) {
            if ($this->telegramDown) {
                return Http::response('', 502);
            }

            if (str_contains($request->url(), 'getWebhookInfo')) {
                return Http::response(['ok' => true, 'result' => $this->webhookInfo]);
            }

            if ($this->sendFails) {
                return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]]);
        });
    }

    private function alerts(): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage') && (int)$pair[0]['chat_id'] === self::GROUP_ID
        ));
    }

    private function deliver(array $update)
    {
        return $this->postJson('guard-test', $update);
    }

    public function test_second_delivery_of_the_same_update_is_not_processed(): void
    {
        $this->deliver(['update_id' => 900001])->assertOk()->assertExactJson(['handled' => true]);
        $this->assertTrue(Cache::has('tg_update:guard-test:900001'));

        // Повтор: до обработчика дело не доходит, ответ мгновенный
        $this->deliver(['update_id' => 900001])->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_different_updates_are_both_let_through(): void
    {
        $this->deliver(['update_id' => 900010])->assertExactJson(['handled' => true]);
        $this->deliver(['update_id' => 900011])->assertExactJson(['handled' => true]);
    }

    public function test_update_without_id_is_always_processed(): void
    {
        $this->deliver([])->assertExactJson(['handled' => true]);
        $this->deliver([])->assertExactJson(['handled' => true]);
    }

    public function test_cache_failure_does_not_lose_the_update(): void
    {
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache is down'));

        $this->deliver(['update_id' => 900030])->assertExactJson(['handled' => true]);
        $this->deliver(['update_id' => 900030])->assertExactJson(['handled' => true]);
    }

    public function test_odd_update_ids_do_not_break_the_guard(): void
    {
        // Нулевой, отрицательный и нечисловой идентификатор не запоминаются: обработчик получает всё
        foreach ([0, -5, 'abc', null] as $id) {
            $this->deliver(['update_id' => $id])->assertExactJson(['handled' => true]);
            $this->deliver(['update_id' => $id])->assertExactJson(['handled' => true]);
        }

        // Числовая строка считается тем же обновлением, что и число
        $this->deliver(['update_id' => '900040'])->assertExactJson(['handled' => true]);
        $this->deliver(['update_id' => 900040])->assertExactJson(['ok' => true]);
    }

    public function test_the_two_bots_do_not_share_update_ids(): void
    {
        foreach (['bot-a', 'bot-b'] as $path) {
            Route::post($path, fn () => response()->json(['handled' => true]))->middleware(SkipDuplicateTelegramUpdate::class);
        }

        $this->postJson('bot-a', ['update_id' => 900050])->assertExactJson(['handled' => true]);
        $this->postJson('bot-b', ['update_id' => 900050])->assertExactJson(['handled' => true]);
        $this->postJson('bot-b', ['update_id' => 900050])->assertExactJson(['ok' => true]);
    }

    public function test_telegram_routes_use_the_guard_after_the_secret_check(): void
    {
        foreach (['api/telegram/bot', 'api/telegram/ai/bot'] as $uri) {
            $middleware = app('router')->getRoutes()->match(Request::create($uri, 'POST'))->gatherMiddleware();
            $this->assertSame([TelegramQuery::class, SkipDuplicateTelegramUpdate::class], array_values(array_diff($middleware, ['api'])), $uri);
        }
    }

    public function test_healthy_webhook_sends_no_alert(): void
    {
        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'pending_update_count' => 0];

        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $this->assertCount(0, $this->alerts());
    }

    public function test_growing_queue_alerts_the_group_once_per_hour(): void
    {
        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'pending_update_count' => 57];

        $this->artisan('telegram:check-webhook')->assertFailed();
        $this->artisan('telegram:check-webhook')->assertFailed();

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('57', $alerts[0][0]['text']);
    }

    public function test_recent_delivery_error_alerts_but_old_one_does_not(): void
    {
        $this->webhookInfo = [
            'url' => 'https://example.test/api/telegram/bot',
            'pending_update_count' => 1,
            'last_error_date' => time() - 3600,
            'last_error_message' => 'Read timeout expired',
        ];

        $this->artisan('telegram:check-webhook')->assertSuccessful();
        $this->assertCount(0, $this->alerts());

        $this->webhookInfo['last_error_date'] = time() - 60;

        $this->artisan('telegram:check-webhook')->assertFailed();
        $this->assertStringContainsString('Read timeout expired', $this->alerts()[0][0]['text']);
    }

    public function test_no_webhook_means_nothing_to_check(): void
    {
        $this->webhookInfo = ['url' => '', 'pending_update_count' => 500];

        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $this->assertCount(0, $this->alerts());
    }

    public function test_no_alert_option_only_reports(): void
    {
        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'pending_update_count' => 99];

        $this->artisan('telegram:check-webhook', ['--no-alert' => true])->assertFailed();

        $this->assertCount(0, $this->alerts());
    }

    public function test_queue_threshold_is_inclusive(): void
    {
        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'pending_update_count' => 19];
        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $this->webhookInfo['pending_update_count'] = 20;
        $this->artisan('telegram:check-webhook')->assertFailed();
    }

    public function test_both_problems_come_in_one_message_and_error_text_is_escaped(): void
    {
        $this->webhookInfo = [
            'url' => 'https://example.test/api/telegram/bot',
            'pending_update_count' => 30,
            'last_error_date' => time() - 30,
            'last_error_message' => 'Wrong response <html> & more',
        ];

        $this->artisan('telegram:check-webhook')->assertFailed();

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('30', $alerts[0][0]['text']);
        $this->assertStringContainsString('Wrong response &lt;html&gt; &amp; more', $alerts[0][0]['text']);
    }

    public function test_error_without_date_or_text_is_ignored(): void
    {
        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'pending_update_count' => 0, 'last_error_message' => 'old'];
        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'last_error_date' => time(), 'last_error_message' => ''];
        $this->artisan('telegram:check-webhook')->assertSuccessful();

        $this->assertCount(0, $this->alerts());
    }

    public function test_failed_alert_is_retried_on_the_next_run(): void
    {
        $this->webhookInfo = ['url' => 'https://example.test/api/telegram/bot', 'pending_update_count' => 50];
        $this->sendFails = true;

        $this->artisan('telegram:check-webhook')->assertFailed();
        $this->sendFails = false;
        $this->artisan('telegram:check-webhook')->assertFailed();

        // Первая отправка не дошла, поэтому вторая проверка пробует снова, а не ждёт час
        $statuses = array_map(fn ($pair) => $pair[1]->status(), $this->alerts());
        $this->assertSame([403, 200], $statuses);
    }

    public function test_telegram_unavailable_is_reported_by_exit_code_without_crashing(): void
    {
        $this->telegramDown = true;

        $this->artisan('telegram:check-webhook')->assertFailed();

        $this->assertCount(0, $this->alerts());
    }

    public function test_command_is_scheduled_with_a_short_overlap_lock(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string)$e->command, 'telegram:check-webhook'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(30, $event->expiresAt);
    }
}
