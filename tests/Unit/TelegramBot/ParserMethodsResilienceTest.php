<?php

namespace Tests\Unit\TelegramBot;

use App\TelegramBot\ParserMethods;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParserMethodsResilienceTest extends TestCase
{
    public function test_post_query_retries_after_a_dropped_connection(): void
    {
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 35: Recv failure: Connection reset by peer');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        $result = ParserMethods::postQuery('https://api.telegram.org/bot123456:ABC/sendMessage', ['chat_id' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $calls);
    }

    public function test_bot_token_is_masked_in_error_text(): void
    {
        Http::fake(function (Request $request) {
            throw new ConnectionException('cURL error 35 for https://api.telegram.org/bot8588837770:AAH3-fvDa_Iw/sendMessage');
        });

        $result = ParserMethods::postQuery('https://api.telegram.org/bot8588837770:AAH3-fvDa_Iw/sendMessage', ['chat_id' => 1]);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('AAH3-fvDa_Iw', $result['result']);
        $this->assertStringContainsString('/bot***/', $result['result']);
    }

    public function test_force_ipv4_config_key_exists_and_is_not_misspelled_anywhere(): void
    {
        // Раньше ParserMethods читал несуществующий ключ traffic_source.telegram.force_ipv4,
        // из-за чего TELEGRAM_FORCE_IPV4 не действовал вообще
        $this->assertNotNull(config('traffic_source.settings.telegram.force_ipv4'));

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->assertStringNotContainsString(
                    "traffic_source.telegram.",
                    (string)file_get_contents($file->getPathname()),
                    $file->getPathname() . ' обращается к несуществующему ключу traffic_source.telegram.*'
                );
            }
        }
    }
}
