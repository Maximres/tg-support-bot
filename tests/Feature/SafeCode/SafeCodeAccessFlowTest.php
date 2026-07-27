<?php

namespace Tests\Feature\SafeCode;

use App\Actions\Telegram\SendAccessMessage;
use App\Actions\Telegram\SetTrustedValue;
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
 * E2E-проверка личного доступа к кодам сейфа/здания и ссылке на орг. информацию
 * без реального Telegram API: входящие обновления строятся напрямую через DTO
 * (как и в остальных тестах проекта), исходящие вызовы к Telegram глушатся Http::fake().
 */
class SafeCodeAccessFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
    }

    private function fakeTelegram(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            if (str_contains($url, 'answerCallbackQuery')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            if (str_contains($url, 'pinChatMessage')) {
                return Http::response(['ok' => true, 'result' => true]);
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

    private function groupCommandDto(string $text, int $fromUserId = 777): TelegramUpdateDto
    {
        $params = [
            'update_id' => time(),
            'message' => [
                'message_id' => time(),
                'from' => [
                    'id' => $fromUserId,
                    'is_bot' => false,
                    'first_name' => 'Admin',
                    'username' => 'admin_user',
                ],
                'chat' => [
                    'id' => -1001234567890,
                    'title' => 'Test Group',
                    'is_forum' => true,
                    'type' => 'supergroup',
                ],
                'date' => time(),
                'text' => $text,
            ],
        ];

        $request = RequestFacade::create('api/telegram/bot', 'POST', $params);
        return TelegramUpdateDto::fromRequest($request);
    }

    public function test_send_access_message_sends_and_pins_once(): void
    {
        $botUser = $this->makeBotUser();

        (new SendAccessMessage())->execute($botUser);

        $botUser->refresh();
        $this->assertNotNull($botUser->access_message_id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pinChatMessage'));

        $sentCountBefore = count(Http::recorded());

        // Повторный вызов — идемпотентность, новых запросов быть не должно
        (new SendAccessMessage())->execute($botUser);

        $this->assertCount($sentCountBefore, Http::recorded());
    }

    public function test_send_access_message_noop_for_non_telegram_platform(): void
    {
        $botUser = $this->makeBotUser(['platform' => 'vk']);

        (new SendAccessMessage())->execute($botUser);

        $botUser->refresh();
        $this->assertNull($botUser->access_message_id);
        $this->assertEmpty(Http::recorded());
    }

    public function test_admin_can_set_both_codes_and_only_trusted_users_are_notified(): void
    {
        $this->chatMemberStatus = 'administrator';

        $trusted = $this->makeBotUser(['is_trusted' => true]);
        $untrusted = $this->makeBotUser(['is_trusted' => false]);

        (new SetTrustedValue())->execute($this->groupCommandDto('/set_code 1234'), SafeCodeType::SAFE);
        (new SetTrustedValue())->execute($this->groupCommandDto('/set_building_code 5678'), SafeCodeType::BUILDING);

        $this->assertSame('1234', SafeCode::current(SafeCodeType::SAFE)->code);
        $this->assertSame('5678', SafeCode::current(SafeCodeType::BUILDING)->code);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && ($r['chat_id'] ?? null) == $trusted->chat_id);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && ($r['chat_id'] ?? null) == $untrusted->chat_id);
    }

    public function test_non_admin_cannot_set_code(): void
    {
        $this->chatMemberStatus = 'member';

        (new SetTrustedValue())->execute($this->groupCommandDto('/set_code 9999'), SafeCodeType::SAFE);

        $this->assertNull(SafeCode::current(SafeCodeType::SAFE));
    }

    public function test_trusted_user_gets_alert_with_code(): void
    {
        SafeCode::create(['code' => '4242', 'type' => SafeCodeType::SAFE->value]);
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        $dto = TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, SafeCodeType::SAFE->callbackData())
        );

        (new ShowTrustedValue())->execute($dto, $botUser, SafeCodeType::SAFE);

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'answerCallbackQuery')) {
                return false;
            }
            $this->assertTrue($r['show_alert']);
            $this->assertStringContainsString('4242', $r['text']);
            $this->assertStringNotContainsString('<', $r['text']);
            return true;
        });
    }

    public function test_untrusted_user_does_not_get_code(): void
    {
        SafeCode::create(['code' => '4242', 'type' => SafeCodeType::SAFE->value]);
        $botUser = $this->makeBotUser(['is_trusted' => false]);

        $dto = TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, SafeCodeType::SAFE->callbackData())
        );

        (new ShowTrustedValue())->execute($dto, $botUser, SafeCodeType::SAFE);

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'answerCallbackQuery')) {
                return false;
            }
            $this->assertStringNotContainsString('4242', $r['text']);
            return true;
        });
    }

    public function test_banned_user_does_not_get_code_even_if_trusted(): void
    {
        SafeCode::create(['code' => '4242', 'type' => SafeCodeType::SAFE->value]);
        $botUser = $this->makeBotUser(['is_trusted' => true, 'is_banned' => true]);

        $dto = TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, SafeCodeType::SAFE->callbackData())
        );

        (new ShowTrustedValue())->execute($dto, $botUser, SafeCodeType::SAFE);

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'answerCallbackQuery')) {
                return false;
            }
            $this->assertStringNotContainsString('4242', $r['text']);
            return true;
        });
    }

    public function test_org_link_button_always_reflects_latest_value(): void
    {
        $this->chatMemberStatus = 'administrator';

        // Ссылка не требует доверия — сотрудник специально недоверенный
        $botUser = $this->makeBotUser(['is_trusted' => false]);

        (new SetTrustedValue())->execute($this->groupCommandDto('/set_org_link https://old.example.com'), SafeCodeType::ORG_LINK);

        $dto = TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, SafeCodeType::ORG_LINK->callbackData())
        );
        (new ShowTrustedValue())->execute($dto, $botUser, SafeCodeType::ORG_LINK);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['reply_markup'] ?? '', 'old.example.com'));

        (new SetTrustedValue())->execute($this->groupCommandDto('/set_org_link https://new.example.com'), SafeCodeType::ORG_LINK);
        (new ShowTrustedValue())->execute($dto, $botUser, SafeCodeType::ORG_LINK);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['reply_markup'] ?? '', 'new.example.com'));
    }

    public function test_unrecognized_callback_data_is_still_acknowledged(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        $dto = TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, 'access_show_unknown_type')
        );

        (new ShowTrustedValue())->execute($dto, $botUser, null);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery'));
    }
}
