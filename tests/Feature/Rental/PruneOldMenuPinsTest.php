<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\HandleRefreshMenusCommand;
use App\Actions\Telegram\PruneOldMenuPins;
use App\Actions\Telegram\RefreshAllAccessMenus;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Дубли закреплённого меню в чате клиента: при обновлении меню закрепы пересобираются
 */
class PruneOldMenuPinsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 5550001;

    private const CURRENT = 300;

    private const GROUP_ID = -1001234567890;

    /** Закрепы в чате клиента: от старых к новым, верхний — последний */
    private array $pins = [];

    /** id закрепов, которые написал не бот (сообщения самого клиента) */
    private array $foreign = [];

    private bool $deleteWorks = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID]);

        Http::fake(function (Request $request) {
            $id = (int)($request['message_id'] ?? 0);

            switch (basename(parse_url($request->url(), PHP_URL_PATH))) {
                case 'getChatMember':
                    return Http::response(['ok' => true, 'result' => ['status' => 'administrator']]);
                case 'getChat':
                    $top = end($this->pins);

                    return Http::response(['ok' => true, 'result' => array_filter([
                        'id' => self::CHAT,
                        'type' => 'private',
                        'pinned_message' => $top ? ['message_id' => $top, 'from' => ['is_bot' => !in_array($top, $this->foreign, true)]] : null,
                    ])]);
                case 'deleteMessage':
                    if (!$this->deleteWorks) {
                        return Http::response(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: message can't be deleted"], 400);
                    }
                    $this->pins = array_values(array_diff($this->pins, [$id]));

                    return Http::response(['ok' => true, 'result' => true]);
                case 'unpinAllChatMessages':
                    $this->pins = [];

                    return Http::response(['ok' => true, 'result' => true]);
                case 'pinChatMessage':
                    $this->pins[] = $id;

                    return Http::response(['ok' => true, 'result' => true]);
                default:
                    return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => self::CHAT, 'type' => 'private'], 'date' => time(), 'text' => '']]);
            }
        });
    }

    private function client(): BotUser
    {
        return BotUser::create([
            'chat_id' => self::CHAT, 'platform' => 'telegram', 'topic_id' => 1,
            'offer_accepted_at' => now(), 'access_message_id' => self::CURRENT,
        ]);
    }

    private function sent(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    public function test_copy_lying_on_top_is_deleted_and_only_the_current_menu_stays_pinned(): void
    {
        $this->pins = [100, self::CURRENT, 400];

        $this->assertTrue((new PruneOldMenuPins())->execute($this->client()));

        $this->assertSame([self::CURRENT], $this->pins);
        $this->assertSame(400, (int)$this->sent('deleteMessage')[0][0]['message_id']);
    }

    public function test_current_menu_on_top_is_left_alone_unless_the_deep_mode_is_used(): void
    {
        $this->pins = [100, 200, self::CURRENT];
        $client = $this->client();

        $this->assertFalse((new PruneOldMenuPins())->execute($client));
        $this->assertSame([100, 200, self::CURRENT], $this->pins);
        $this->assertCount(0, $this->sent('unpinAllChatMessages'));

        $this->assertTrue((new PruneOldMenuPins())->execute($client, true));
        $this->assertSame([self::CURRENT], $this->pins);
        // Актуальное меню удалять нельзя
        $this->assertCount(0, $this->sent('deleteMessage'));
    }

    public function test_nothing_pinned_means_nothing_is_pinned_again(): void
    {
        $this->pins = [];

        $this->assertFalse((new PruneOldMenuPins())->execute($this->client(), true));
        $this->assertSame([], $this->pins);
        $this->assertCount(0, $this->sent('pinChatMessage'));
    }

    public function test_clients_own_pin_on_top_is_left_alone(): void
    {
        $this->pins = [self::CURRENT, 777];
        $this->foreign = [777];

        $this->assertFalse((new PruneOldMenuPins())->execute($this->client(), true));
        $this->assertSame([self::CURRENT, 777], $this->pins);
        $this->assertCount(0, $this->sent('deleteMessage'));
        $this->assertCount(0, $this->sent('unpinAllChatMessages'));
    }

    public function test_copy_that_cannot_be_deleted_loses_its_buttons_and_is_unpinned(): void
    {
        $this->deleteWorks = false;
        $this->pins = [self::CURRENT, 400];

        $this->assertTrue((new PruneOldMenuPins())->execute($this->client()));

        $this->assertSame([self::CURRENT], $this->pins);
        $this->assertCount(1, $this->sent('editMessageReplyMarkup'));
    }

    public function test_bulk_refresh_counts_cleaned_chats_and_clean_goes_deeper(): void
    {
        $this->client();
        $this->pins = [self::CURRENT, 400];

        $result = (new RefreshAllAccessMenus())->execute();
        $this->assertSame(['done' => 1, 'failed' => 0, 'removed' => 1], $result);
        $this->assertSame([self::CURRENT], $this->pins);

        // Под актуальным меню копий не видно: обычное обновление ничего не пересобирает, «clean» — пересобирает
        $this->pins = [100, self::CURRENT];
        $this->assertSame(0, (new RefreshAllAccessMenus())->execute()['removed']);
        $this->assertSame([100, self::CURRENT], $this->pins);

        $this->assertSame(1, (new RefreshAllAccessMenus())->execute(true)['removed']);
        $this->assertSame([self::CURRENT], $this->pins);
    }

    public function test_group_command_with_clean_argument_cleans_hidden_copies(): void
    {
        $this->client();
        $this->pins = [100, self::CURRENT];

        (new HandleRefreshMenusCommand())->execute($this->groupCommand('/refresh_menus'), '/refresh_menus');
        $this->assertSame([100, self::CURRENT], $this->pins);

        (new HandleRefreshMenusCommand())->execute($this->groupCommand('/refresh_menus clean'), '/refresh_menus');
        $this->assertSame([self::CURRENT], $this->pins);
    }

    private function groupCommand(string $text): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_ID, 'title' => 'Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $text,
            ],
        ]));
    }
}
