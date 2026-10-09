<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\BannedContactMessage;
use App\Actions\Telegram\RefreshAccessMessage;
use App\Actions\Telegram\SendAccessMessage;
use App\Actions\Telegram\SetRentalLink;
use App\Actions\Telegram\ShowRentalMaterial;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotSetting;
use App\Models\BotUser;
use App\Services\Rental\RentalLinks;
use App\Services\Rental\RotationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\Mocks\Tg\TelegramUpdate_SafeCodeButtonMock;
use Tests\TestCase;

/**
 * Ротация ссылок: выдача по нажатию, команда /set_link, обновление меню и сводное уведомление
 */
class LinkRotationTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private string $chatMemberStatus = 'administrator';

    /** Ответ Telegram на editMessageText: null — успех, иначе описание ошибки */
    private ?string $editError = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'traffic_source.settings.telegram.group_id' => self::GROUP_ID,
            'rental.offer_document' => 'https://example.com/offer.pdf',
            'rental.links' => [
                'hub' => 'https://example.com/hub',
                'cabinets' => null,
                'map' => null,
                'schedule' => 'https://example.com/schedule',
                'payment' => null,
                'wifi' => 'https://example.com/wifi',
                'keys' => 'https://example.com/keys',
            ],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            if (str_contains($request->url(), 'editMessageText') && $this->editError !== null) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => $this->editError]);
            }

            return Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => random_int(1000, 999999),
                    'chat' => ['id' => $request['chat_id'] ?? 0, 'type' => 'private'],
                    'date' => time(),
                    'text' => $request['text'] ?? '',
                ],
            ]);
        });
    }

    private function makeBotUser(array $overrides = []): BotUser
    {
        return BotUser::create(array_merge([
            'chat_id' => random_int(1, 2_000_000_000),
            'platform' => 'telegram',
            'topic_id' => random_int(1, 100000),
            'is_banned' => false,
            'is_trusted' => false,
        ], $overrides));
    }

    private function callbackDto(BotUser $botUser, string $data): TelegramUpdateDto
    {
        return TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, $data)
        );
    }

    private function groupCommand(string $text): TelegramUpdateDto
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

    /** reply_markup как строка JSON: прямые запросы шлют массив, джобы — строку */
    private function markup(Request $request): string
    {
        $markup = $request['reply_markup'] ?? '';

        if (is_string($markup)) {
            $markup = json_decode($markup, true) ?? [];
        }

        return json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sentCount(string $method, ?callable $filter = null): int
    {
        return count(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method && ($filter === null || $filter($pair[0]))
        ));
    }

    private function sentToUser(BotUser $botUser, string $method = 'sendMessage'): int
    {
        return $this->sentCount($method, fn (Request $r) => (int)($r['chat_id'] ?? 0) === $botUser->chat_id);
    }

    // ---- выдача ссылки по нажатию

    public function test_trusted_user_gets_the_current_link_and_it_follows_rotation(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_schedule'), $botUser, 'schedule');
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($this->markup($r), 'example.com/schedule')));

        RentalLinks::set('schedule', 'https://new.example.com/sheet');

        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_schedule'), $botUser, 'schedule');
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($this->markup($r), 'new.example.com/sheet')));
    }

    public function test_untrusted_and_banned_users_do_not_get_links(): void
    {
        $untrusted = $this->makeBotUser(['is_trusted' => false]);
        $banned = $this->makeBotUser(['is_trusted' => true, 'is_banned' => true]);

        (new ShowRentalMaterial())->showLink($this->callbackDto($untrusted, 'link_show_wifi'), $untrusted, 'wifi');
        (new ShowRentalMaterial())->showLink($this->callbackDto($banned, 'link_show_wifi'), $banned, 'wifi');

        $this->assertSame(0, $this->sentCount('sendMessage'));
        $this->assertSame(2, $this->sentCount('answerCallbackQuery', fn ($r) => $r['show_alert'] === true));
    }

    public function test_unset_or_unknown_link_is_answered_without_sending_anything(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        // Ссылка «оплата» не задана
        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_payment'), $botUser, 'payment');
        // Такого пункта нет
        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_nope'), $botUser, 'nope');

        $this->assertSame(0, $this->sentCount('sendMessage'));
        $this->assertSame(2, $this->sentCount('answerCallbackQuery'));
    }

    public function test_public_links_are_given_without_access_but_not_to_banned_users(): void
    {
        config(['rental.links.map' => 'https://example.com/map', 'rental.links.cabinets' => 'https://example.com/cabinets']);

        $untrusted = $this->makeBotUser(['is_trusted' => false]);
        $banned = $this->makeBotUser(['is_trusted' => true, 'is_banned' => true]);

        (new ShowRentalMaterial())->showLink($this->callbackDto($untrusted, 'link_show_cabinets'), $untrusted, 'cabinets');
        (new ShowRentalMaterial())->showLink($this->callbackDto($untrusted, 'link_show_map'), $untrusted, 'map');
        (new ShowRentalMaterial())->showLink($this->callbackDto($banned, 'link_show_map'), $banned, 'map');

        $this->assertSame(2, $this->sentToUser($untrusted));
        $this->assertSame(0, $this->sentToUser($banned));
    }

    // ---- /set_link

    public function test_admin_sets_link_by_russian_and_english_names(): void
    {
        (new SetRentalLink())->execute($this->groupCommand('/set_link график https://a.example.com/s#gid=1'));
        $this->assertSame('https://a.example.com/s#gid=1', RentalLinks::get('schedule'));

        (new SetRentalLink())->execute($this->groupCommand('/set_link wifi https://b.example.com/w'));
        $this->assertSame('https://b.example.com/w', RentalLinks::get('wifi'));
    }

    public function test_non_admin_cannot_set_link(): void
    {
        $this->chatMemberStatus = 'member';

        (new SetRentalLink())->execute($this->groupCommand('/set_link график https://evil.example.com'));

        $this->assertSame('https://example.com/schedule', RentalLinks::get('schedule'));
    }

    public function test_invalid_unknown_and_unchanged_input_changes_nothing(): void
    {
        (new SetRentalLink())->execute($this->groupCommand('/set_link график не-ссылка'));
        (new SetRentalLink())->execute($this->groupCommand('/set_link график ftp://example.com/x'));
        (new SetRentalLink())->execute($this->groupCommand('/set_link нетакого https://example.com/x'));
        (new SetRentalLink())->execute($this->groupCommand('/set_link'));
        (new SetRentalLink())->execute($this->groupCommand('/set_link график https://example.com/schedule'));

        $this->assertSame('https://example.com/schedule', RentalLinks::get('schedule'));
        $this->assertSame([], json_decode((string)BotSetting::get('rotation.pending', '[]'), true));
    }

    public function test_off_hides_the_item_even_if_it_comes_from_env(): void
    {
        $this->assertContains('link_show_keys', array_column(array_merge(...(new SendAccessMessage())->getKeyboard(true)), 'callback_data'));

        (new SetRentalLink())->execute($this->groupCommand('/set_link ключи off'));

        $this->assertNull(RentalLinks::get('keys'));
        $this->assertNotContains('link_show_keys', array_column(array_merge(...(new SendAccessMessage())->getKeyboard(true)), 'callback_data'));
    }

    // ---- сводное уведомление

    public function test_rotation_sends_one_summary_to_trusted_users_after_quiet_period(): void
    {
        $trusted = $this->makeBotUser(['is_trusted' => true]);
        $untrusted = $this->makeBotUser(['is_trusted' => false]);
        $banned = $this->makeBotUser(['is_trusted' => true, 'is_banned' => true]);

        (new SetRentalLink())->execute($this->groupCommand('/set_link график https://new.example.com/s'));
        (new SetRentalLink())->execute($this->groupCommand('/set_link wifi https://new.example.com/w'));

        // Только что менялось — ждём
        (new RotationNotifier())->flushIfQuiet();
        $this->assertSame(0, $this->sentToUser($trusted));

        BotSetting::set('rotation.last_at', (string)(time() - RotationNotifier::QUIET_SECONDS - 5));
        (new RotationNotifier())->flushIfQuiet();

        $this->assertSame(1, $this->sentToUser($trusted));
        $this->assertSame(0, $this->sentToUser($untrusted));
        $this->assertSame(0, $this->sentToUser($banned));
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'График') && str_contains($r['text'] ?? '', 'Wi-Fi')));
        $this->assertSame(0, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'new.example.com')));

        // Повторный запуск планировщика ничего не отправляет
        (new RotationNotifier())->flushIfQuiet();
        $this->assertSame(1, $this->sentToUser($trusted));
    }

    // ---- обновление меню

    public function test_refresh_edits_the_pinned_menu_in_place_and_notifies(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true, 'offer_accepted_at' => now(), 'access_message_id' => 555]);

        $this->assertTrue((new RefreshAccessMessage())->execute($botUser, true));

        $this->assertSame(1, $this->sentCount('editMessageText', fn ($r) => (int)$r['message_id'] === 555 && str_contains($this->markup($r), 'link_show_hub')));
        $this->assertSame(0, $this->sentCount('pinChatMessage'));
        $this->assertSame(1, $this->sentToUser($botUser));
    }

    public function test_refresh_treats_not_modified_as_success(): void
    {
        $this->editError = 'Bad Request: message is not modified';
        $botUser = $this->makeBotUser(['is_trusted' => true, 'offer_accepted_at' => now(), 'access_message_id' => 555]);

        $this->assertTrue((new RefreshAccessMessage())->execute($botUser));

        $this->assertSame(0, $this->sentCount('pinChatMessage'));
        $this->assertSame(0, $this->sentToUser($botUser));
    }

    public function test_refresh_sends_a_new_menu_when_the_old_one_was_deleted(): void
    {
        $this->editError = 'Bad Request: message to edit not found';
        $botUser = $this->makeBotUser(['is_trusted' => true, 'offer_accepted_at' => now(), 'access_message_id' => 555]);

        $this->assertTrue((new RefreshAccessMessage())->execute($botUser));

        $this->assertSame(1, $this->sentCount('pinChatMessage'));
        $this->assertSame(1, $this->sentToUser($botUser));
        $this->assertNotSame(555, (int)$botUser->fresh()->access_message_id);
    }

    public function test_refresh_does_nothing_before_the_offer_is_accepted(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        $this->assertFalse((new RefreshAccessMessage())->execute($botUser, true));

        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_replacing_the_menu_removes_the_old_message(): void
    {
        $botUser = $this->makeBotUser(['offer_accepted_at' => now(), 'access_message_id' => 356]);

        (new SendAccessMessage())->execute($botUser, true);

        $this->assertSame(1, $this->sentCount('deleteMessage', fn ($r) => (int)$r['message_id'] === 356));
        $this->assertNotSame(356, (int)$botUser->fresh()->access_message_id);
    }

    // ---- блокировка

    public function test_ban_collapses_the_menu_without_notice_and_reminds_the_admins(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true, 'offer_accepted_at' => now(), 'access_message_id' => 555]);

        (new BannedContactMessage())->execute($botUser, true);

        // Меню клиента правится на месте и теперь без материалов
        $edits = array_values(array_filter(Http::recorded()->all(), fn ($pair) => str_contains($pair[0]->url(), 'editMessageText') && (int)$pair[0]['message_id'] === 555));
        $this->assertCount(1, $edits);
        $this->assertStringNotContainsString('link_show_', $this->markup($edits[0][0]));
        $this->assertSame(0, $this->sentToUser($botUser));

        // В топике клиента — напоминание с кнопкой ротации
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => (int)($r['message_thread_id'] ?? 0) === $botUser->topic_id
            && str_contains($this->markup($r), 'panel:rotate')));
    }

    public function test_unban_brings_the_menu_back(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true, 'is_banned' => true, 'offer_accepted_at' => now(), 'access_message_id' => 555]);

        (new BannedContactMessage())->execute($botUser, false);

        $this->assertSame(1, $this->sentCount('editMessageText', fn ($r) => str_contains($this->markup($r), 'link_show_hub')));
        $this->assertSame(0, $this->sentCount('sendMessage', fn ($r) => str_contains($this->markup($r), 'panel:rotate')));
    }
}
