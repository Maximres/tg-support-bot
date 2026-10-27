<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\HandleRefreshMenusCommand;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * /refresh_menus: администратор обновляет закреплённое меню у всех клиентов
 */
class RefreshMenusCommandTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]]);
        });
    }

    private function groupCommand(string $text = '/refresh_menus'): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => [
                'message_id' => random_int(1, 1000),
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_ID, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $text,
            ],
        ]));
    }

    private function client(int $chatId, array $overrides = []): BotUser
    {
        return BotUser::create(array_merge([
            'chat_id' => $chatId,
            'platform' => 'telegram',
            'topic_id' => $chatId,
            'offer_accepted_at' => now(),
            'access_message_id' => 100 + $chatId % 1000,
        ], $overrides));
    }

    private function sent(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    private function groupTexts(): array
    {
        return array_map(
            fn ($pair) => $pair[0]['text'],
            array_filter($this->sent('sendMessage'), fn ($pair) => (int)$pair[0]['chat_id'] === self::GROUP_ID)
        );
    }

    public function test_menus_of_all_clients_are_edited_in_place_without_notifying_them(): void
    {
        $this->client(5550001);
        $this->client(5550002);
        // Нет закреплённого меню или не принят договор — этим клиентам ничего не отправляется
        $this->client(5550003, ['access_message_id' => null]);
        $this->client(5550004, ['offer_accepted_at' => null]);

        (new HandleRefreshMenusCommand())->execute($this->groupCommand(), '/refresh_menus');

        $edits = $this->sent('editMessageText');
        $this->assertCount(2, $edits);
        $this->assertEqualsCanonicalizing([5550001, 5550002], array_map(fn ($pair) => (int)$pair[0]['chat_id'], $edits));

        // Клиентам не уходит ни одного нового сообщения
        $clientMessages = array_filter($this->sent('sendMessage'), fn ($pair) => (int)$pair[0]['chat_id'] !== self::GROUP_ID);
        $this->assertCount(0, $clientMessages);

        $this->assertContains(__('messages.refresh_menus.done', ['done' => 2, 'failed' => 0, 'removed' => 0]) . "\n\n" . __('messages.refresh_menus.clean_hint'), $this->groupTexts());
    }

    public function test_only_admins_can_run_it(): void
    {
        $this->chatMemberStatus = 'member';
        $this->client(5550001);

        (new HandleRefreshMenusCommand())->execute($this->groupCommand(), '/refresh_menus');

        $this->assertCount(0, $this->sent('editMessageText'));
        $this->assertContains(__('messages.command_admin_only'), $this->groupTexts());
    }

    public function test_second_run_is_refused_while_the_first_is_still_going(): void
    {
        $this->client(5550001);
        $lock = Cache::lock('refresh_all_access_menus', 600);
        $this->assertTrue($lock->get());

        (new HandleRefreshMenusCommand())->execute($this->groupCommand(), '/refresh_menus');

        $this->assertCount(0, $this->sent('editMessageText'));
        $this->assertContains(__('messages.refresh_menus.already_running'), $this->groupTexts());

        $lock->release();
    }

    public function test_artisan_command_uses_the_same_logic(): void
    {
        $this->client(5550001);

        $this->artisan('telegram:refresh-access-menus')->assertSuccessful();

        $this->assertCount(1, $this->sent('editMessageText'));
    }
}
