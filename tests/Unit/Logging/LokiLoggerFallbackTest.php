<?php

namespace Tests\Unit\Logging;

use App\Logging\LokiLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Loki не настроен (пустой URL) — ошибки всё равно должны попадать в обычный лог приложения,
 * иначе исключения в десятках мест (в том числе в задачах отправки сообщений) пропадают бесследно
 */
class LokiLoggerFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['loki_custom.url' => '']);
    }

    public function test_exception_is_written_to_the_application_log_when_loki_is_not_configured(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'error'
                && str_contains($message, 'relay failed')
                && ($context['file'] ?? null) !== null);

        $this->assertTrue((new LokiLogger())->logException(new \RuntimeException('relay failed')));
    }

    public function test_warning_level_is_kept_for_code_1_exceptions(): void
    {
        Log::shouldReceive('log')->once()->withArgs(fn ($level) => $level === 'warning');

        (new LokiLogger())->logException(new \Exception('soft problem', 1));
    }

    public function test_bot_token_is_masked_before_logging(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message) => !str_contains($message, 'SECRETPART') && str_contains($message, '/bot***/'));

        (new LokiLogger())->logException(new \RuntimeException('cURL error for https://api.telegram.org/bot123456:SECRETPART/sendMessage'));
    }

    public function test_plain_messages_are_logged_too(): void
    {
        Log::shouldReceive('log')->once()->withArgs(fn ($level, $message) => $level === 'info' && str_contains($message, 'hello'));

        $this->assertTrue((new LokiLogger())->log('info', 'hello'));
    }
}
