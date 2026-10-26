<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\SetTrustedValue;
use App\Actions\Telegram\ShowRentalMaterial;
use App\Actions\Telegram\ShowTrustedValue;
use App\DTOs\TelegramUpdateDto;
use App\Enums\SafeCodeType;
use App\Models\BotUser;
use App\Models\SafeCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\Mocks\Tg\TelegramUpdate_SafeCodeButtonMock;
use Tests\TestCase;

/**
 * Правки владельца: Wi-Fi текстом, кнопка «Коды», сообщение «График» с приложениями
 */
class OwnerEditsMenuTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'traffic_source.settings.telegram.group_id' => self::GROUP_ID,
            'rental.links' => ['schedule' => 'https://example.com/schedule', 'keys' => 'https://example.com/keys'],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 99999), 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]]);
        });
    }

    private function makeBotUser(array $overrides = []): BotUser
    {
        return BotUser::create(array_merge([
            'chat_id' => random_int(1, 2_000_000_000),
            'platform' => 'telegram',
            'topic_id' => random_int(1, 100000),
            'is_banned' => false,
            'is_trusted' => true,
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

    private function markup(Request $request): string
    {
        $markup = $request['reply_markup'] ?? '';

        if (is_string($markup)) {
            $markup = json_decode($markup, true) ?? [];
        }

        return json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sent(string $method, ?callable $filter = null): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method && ($filter === null || $filter($pair[0]))
        ));
    }

    // ---- «Коды»

    public function test_codes_button_shows_both_codes_in_one_alert(): void
    {
        SafeCode::create(['code' => '1111', 'type' => SafeCodeType::SAFE->value]);
        SafeCode::create(['code' => '2222', 'type' => SafeCodeType::BUILDING->value]);
        $botUser = $this->makeBotUser();

        (new ShowTrustedValue())->showCodes($this->callbackDto($botUser, 'access_show_codes'), $botUser);

        $alerts = $this->sent('answerCallbackQuery', fn ($r) => $r['show_alert'] === true);
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Код сейфа: 1111', $alerts[0][0]['text']);
        $this->assertStringContainsString('Код здания: 2222', $alerts[0][0]['text']);
    }

    public function test_codes_button_reports_missing_code_and_denies_without_access(): void
    {
        SafeCode::create(['code' => '1111', 'type' => SafeCodeType::SAFE->value]);

        $trusted = $this->makeBotUser();
        (new ShowTrustedValue())->showCodes($this->callbackDto($trusted, 'access_show_codes'), $trusted);
        $this->assertStringContainsString('Код здания: не задан', $this->sent('answerCallbackQuery')[0][0]['text']);

        $untrusted = $this->makeBotUser(['is_trusted' => false]);
        $banned = $this->makeBotUser(['is_banned' => true]);
        (new ShowTrustedValue())->showCodes($this->callbackDto($untrusted, 'access_show_codes'), $untrusted);
        (new ShowTrustedValue())->showCodes($this->callbackDto($banned, 'access_show_codes'), $banned);

        $texts = array_map(fn ($pair) => $pair[0]['text'], $this->sent('answerCallbackQuery'));
        $this->assertStringNotContainsString('1111', $texts[1]);
        $this->assertStringNotContainsString('1111', $texts[2]);
    }

    // ---- Wi-Fi

    public function test_admin_sets_multiline_wifi_text(): void
    {
        (new SetTrustedValue())->execute($this->groupCommand("/set_wifi Сеть: Garetski.com\nпароль: 3975831"), SafeCodeType::WIFI);

        $this->assertSame("Сеть: Garetski.com\nпароль: 3975831", SafeCode::current(SafeCodeType::WIFI)->code);
    }

    public function test_wifi_text_is_validated_and_admin_only(): void
    {
        (new SetTrustedValue())->execute($this->groupCommand('/set_wifi ' . str_repeat('я', 501)), SafeCodeType::WIFI);
        $this->assertNull(SafeCode::current(SafeCodeType::WIFI));

        $this->chatMemberStatus = 'member';
        (new SetTrustedValue())->execute($this->groupCommand('/set_wifi Сеть: A'), SafeCodeType::WIFI);
        $this->assertNull(SafeCode::current(SafeCodeType::WIFI));
    }

    public function test_wifi_is_sent_as_a_message_to_users_with_access(): void
    {
        SafeCode::create(['code' => "Сеть: Garetski.com\nпароль: <3975831>", 'type' => SafeCodeType::WIFI->value]);

        $trusted = $this->makeBotUser();
        $untrusted = $this->makeBotUser(['is_trusted' => false]);

        (new ShowTrustedValue())->execute($this->callbackDto($trusted, 'access_show_wifi'), $trusted, SafeCodeType::WIFI);
        (new ShowTrustedValue())->execute($this->callbackDto($untrusted, 'access_show_wifi'), $untrusted, SafeCodeType::WIFI);

        $messages = $this->sent('sendMessage');
        $this->assertCount(1, $messages);
        $this->assertSame($trusted->chat_id, (int)$messages[0][0]['chat_id']);
        // Спецсимволы экранируются, чтобы Telegram не принял пароль за разметку
        $this->assertStringContainsString("Сеть: Garetski.com\nпароль: &lt;3975831&gt;", $messages[0][0]['text']);
    }

    public function test_legacy_wifi_link_button_from_an_old_menu_shows_the_text(): void
    {
        SafeCode::create(['code' => 'Сеть: Test', 'type' => SafeCodeType::WIFI->value]);
        $botUser = $this->makeBotUser();

        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_wifi'), $botUser, 'wifi');

        $this->assertStringContainsString('Сеть: Test', $this->sent('sendMessage')[0][0]['text']);
    }

    // ---- «График» с подсказкой про приложение

    public function test_schedule_message_mentions_the_mobile_app_and_has_its_own_button(): void
    {
        $botUser = $this->makeBotUser();

        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_schedule'), $botUser, 'schedule');

        $message = $this->sent('sendMessage')[0][0];
        $this->assertStringContainsString('График — актуальная ссылка', $message['text']);
        $this->assertStringContainsString('Google Таблицы', $message['text']);
        $this->assertStringContainsString(config('rental.apps.sheets_android'), $message['text']);
        $this->assertStringContainsString(config('rental.apps.sheets_ios'), $message['text']);
        $this->assertStringContainsString('Открыть график', $this->markup($message));
        $this->assertStringContainsString('https://example.com/schedule', $this->markup($message));
    }

    public function test_other_links_keep_the_generic_message_and_button(): void
    {
        $botUser = $this->makeBotUser();

        (new ShowRentalMaterial())->showLink($this->callbackDto($botUser, 'link_show_keys'), $botUser, 'keys');

        $message = $this->sent('sendMessage')[0][0];
        $this->assertStringContainsString('Ключи — актуальная ссылка', $message['text']);
        $this->assertStringNotContainsString('Google Таблицы', $message['text']);
        $this->assertStringContainsString('"text":"Открыть"', $this->markup($message));
    }
}
