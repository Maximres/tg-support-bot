<?php

namespace Tests\Feature\Topic;

use App\Actions\Telegram\VerifyGroupAdmin;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use App\Services\Tg\TgMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Администратор, написавший в группу анонимно («Оставаться анонимным»), приходит как служебный
 * бот GroupAnonymousBot с is_bot=true. Раньше такие сообщения принимались за бота и молча
 * не доходили до клиента, а команды не проходили проверку прав.
 */
class AnonymousAdminTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1003530272340;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            return Http::response(['ok' => true, 'result' => [
                'message_id' => random_int(1000, 999999),
                'chat' => ['id' => $request['chat_id'] ?? 0, 'type' => 'private'],
                'date' => time(),
                'text' => $request['text'] ?? '',
            ]]);
        });
    }

    private function groupMessage(array $from, array $extra = []): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => array_merge([
                'message_id' => random_int(1, 1000),
                'message_thread_id' => 448,
                'from' => $from,
                'chat' => ['id' => self::GROUP_ID, 'title' => 'GarSuppTopicsGroup', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => 'Пишу!',
            ], $extra),
        ]));
    }

    private function anonymousAdmin(array $extra = []): TelegramUpdateDto
    {
        return $this->groupMessage(
            ['id' => TelegramUpdateDto::ANONYMOUS_ADMIN_ID, 'is_bot' => true, 'first_name' => 'Group', 'username' => 'GroupAnonymousBot'],
            array_merge(['sender_chat' => ['id' => self::GROUP_ID, 'title' => 'GarSuppTopicsGroup', 'type' => 'supergroup']], $extra)
        );
    }

    public function test_anonymous_admin_is_not_treated_as_a_bot(): void
    {
        $this->assertFalse($this->anonymousAdmin()->isBot);
    }

    public function test_real_bots_are_still_treated_as_bots(): void
    {
        $bot = $this->groupMessage(['id' => 8588837770, 'is_bot' => true, 'first_name' => 'Gar Supp Topics Bot 2', 'username' => 'GarSuppTopicsBot']);

        $this->assertTrue($bot->isBot);
    }

    public function test_regular_users_are_not_bots(): void
    {
        $user = $this->groupMessage(['id' => 777, 'is_bot' => false, 'first_name' => 'Konstantin']);

        $this->assertFalse($user->isBot);
    }

    public function test_anonymous_admin_passes_the_admin_check_without_asking_telegram(): void
    {
        $this->assertTrue(VerifyGroupAdmin::check(self::GROUP_ID, TelegramUpdateDto::ANONYMOUS_ADMIN_ID));

        Http::assertNothingSent();
    }

    public function test_message_from_an_anonymous_admin_reaches_the_client(): void
    {
        $client = BotUser::create(['chat_id' => 555666, 'platform' => 'telegram', 'topic_id' => 448]);

        (new TgMessageService($this->anonymousAdmin()))->handleUpdate();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && (int)$r['chat_id'] === $client->chat_id
            && $r['text'] === 'Пишу!');
    }
}
