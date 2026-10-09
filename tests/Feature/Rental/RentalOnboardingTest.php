<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\AcceptOffer;
use App\Actions\Telegram\SendAccessMessage;
use App\Actions\Telegram\SendContactMessage;
use App\Actions\Telegram\SendOfferMessage;
use App\Actions\Telegram\ShowRentalMaterial;
use App\Enums\SafeCodeType;
use App\Models\BotUser;
use App\Models\SafeCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Mocks\Tg\TelegramUpdate_SafeCodeButtonMock;
use Tests\TestCase;

/**
 * Онбординг арендатора: договор-оферта -> согласие -> меню материалов
 */
class RentalOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
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

        config([
            'rental.offer_document' => 'https://example.com/offer.pdf',
            'rental.emergency_phone' => '+375291234567',
            'rental.links' => [
                'hub' => 'https://example.com/hub',
                'cabinets' => 'https://example.com/cabinets',
                'map' => null,
                'schedule' => 'https://example.com/schedule',
                'payment' => null,
                'wifi' => 'https://example.com/wifi',
                'keys' => 'https://example.com/keys',
            ],
        ]);
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

    private function callbackDto(BotUser $botUser, string $data)
    {
        return TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams($botUser->chat_id, $data)
        );
    }

    private function sentCount(string $method): int
    {
        return count(array_filter(
            Http::recorded()->all(),
            fn ($pair) => str_contains($pair[0]->url(), $method)
        ));
    }

    public function test_offer_is_sent_as_document_with_accept_button(): void
    {
        $botUser = $this->makeBotUser();

        (new SendOfferMessage())->execute($botUser);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendDocument')
            && $r['document'] === 'https://example.com/offer.pdf'
            && str_contains($r['reply_markup'] ?? '', 'offer_accept'));
        $this->assertSame(0, $this->sentCount('pinChatMessage'));
    }

    public function test_offer_falls_back_to_text_without_document(): void
    {
        config(['rental.offer_document' => null]);
        $botUser = $this->makeBotUser();

        (new SendOfferMessage())->execute($botUser);

        $this->assertSame(0, $this->sentCount('sendDocument'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['reply_markup'] ?? '', 'offer_accept'));
    }

    public function test_accepting_offer_records_time_and_sends_pinned_menu(): void
    {
        $botUser = $this->makeBotUser();

        (new AcceptOffer())->execute($this->callbackDto($botUser, 'offer_accept'), $botUser);

        $botUser->refresh();
        $this->assertNotNull($botUser->offer_accepted_at);
        $this->assertNotNull($botUser->access_message_id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageReplyMarkup'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pinChatMessage'));

        // Одно сообщение: благодарность, телефон и меню; отдельного приветствия нет
        $menus = array_filter(
            Http::recorded()->all(),
            fn ($pair) => str_contains($pair[0]->url(), 'sendMessage') && str_contains($pair[0]['text'] ?? '', 'Договор-оферта принят')
        );
        $this->assertCount(1, $menus);
        $this->assertStringContainsString('+375291234567', array_values($menus)[0][0]['text']);
        $toClient = array_filter(
            Http::recorded()->all(),
            fn ($pair) => str_contains($pair[0]->url(), 'sendMessage') && (int)($pair[0]['chat_id'] ?? 0) === $botUser->chat_id
        );
        $this->assertCount(1, $toClient);
    }

    public function test_accepting_offer_twice_does_not_resend_menu(): void
    {
        $botUser = $this->makeBotUser();
        $dto = $this->callbackDto($botUser, 'offer_accept');

        (new AcceptOffer())->execute($dto, $botUser);
        $firstAcceptedAt = $botUser->fresh()->offer_accepted_at;

        (new AcceptOffer())->execute($dto, $botUser->fresh());

        // Закрепляется только меню в чате клиента (карточка в группе — отдельное закрепление)
        $this->assertSame(1, count(array_filter(
            Http::recorded()->all(),
            fn ($pair) => str_contains($pair[0]->url(), 'pinChatMessage') && (int)$pair[0]['chat_id'] > 0
        )));
        $this->assertEquals($firstAcceptedAt, $botUser->fresh()->offer_accepted_at);
    }

    public function test_banned_user_cannot_accept_offer(): void
    {
        $botUser = $this->makeBotUser(['is_banned' => true]);

        (new AcceptOffer())->execute($this->callbackDto($botUser, 'offer_accept'), $botUser);

        $this->assertNull($botUser->fresh()->offer_accepted_at);
        $this->assertSame(0, $this->sentCount('pinChatMessage'));
    }

    public function test_user_who_already_accepted_gets_menu_instead_of_offer(): void
    {
        $botUser = $this->makeBotUser(['offer_accepted_at' => now()]);

        (new SendOfferMessage())->execute($botUser);

        $this->assertSame(0, $this->sentCount('sendDocument'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pinChatMessage'));
    }

    public function test_menu_without_access_has_only_the_public_items(): void
    {
        config(['rental.links.map' => 'https://example.com/map']);
        SafeCode::create(['code' => 'https://example.com/rules', 'type' => SafeCodeType::ORG_LINK->value]);

        $keyboard = (new SendAccessMessage())->getKeyboard(false);

        // Описание кабинетов сверху, затем правила, как добраться и договор
        $this->assertSame([
            [['text' => '🏠 Описание кабинетов', 'callback_data' => 'link_show_cabinets']],
            [
                ['text' => '📋 Правила', 'callback_data' => 'access_show_org_link'],
                ['text' => '🗺 Как добраться', 'callback_data' => 'link_show_map'],
            ],
            [['text' => '📄 Договор', 'callback_data' => 'offer_show']],
        ], $keyboard);
    }

    public function test_menu_without_access_hides_everything_else(): void
    {
        $flat = json_encode((new SendAccessMessage())->getKeyboard(false), JSON_UNESCAPED_UNICODE);

        foreach (['link_show_hub', 'link_show_schedule', 'link_show_wifi', 'link_show_keys', 'access_show_safe', 'access_show_building'] as $callback) {
            $this->assertStringNotContainsString($callback, $flat);
        }
    }

    public function test_menu_is_empty_for_banned_user_and_when_nothing_is_set(): void
    {
        $this->assertSame([], (new SendAccessMessage())->getKeyboard(true, true));

        config(['rental.offer_document' => null, 'rental.links' => []]);

        $this->assertSame([], (new SendAccessMessage())->getKeyboard(false));
    }

    public function test_menu_with_access_has_every_item_but_no_direct_links(): void
    {
        SafeCode::create(['code' => 'https://example.com/rules', 'type' => SafeCodeType::ORG_LINK->value]);

        $keyboard = (new SendAccessMessage())->getKeyboard(true);
        $flat = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Порядок: описание кабинетов сверху, затем правила, как добраться, потом остальное, договор в конце
        $this->assertCount(1, $keyboard[0]);
        $this->assertSame('🏠 Описание кабинетов', $keyboard[0][0]['text']);
        $this->assertSame('link_show_cabinets', $keyboard[0][0]['callback_data']);
        $this->assertSame('📋 Правила', $keyboard[1][0]['text']);
        // (карта в этой конфигурации не задана, поэтому после правил сразу идут остальные материалы)
        $this->assertSame('📋 Все инструкции', $keyboard[1][1]['text']);
        $this->assertSame('link_show_hub', $keyboard[1][1]['callback_data']);

        // Договор — последний
        $last = end($keyboard);
        $this->assertSame('offer_show', end($last)['callback_data']);

        foreach (['link_show_cabinets', 'link_show_wifi', 'link_show_schedule', 'link_show_keys', 'access_show_safe', 'access_show_building', 'access_show_org_link', 'offer_show'] as $callback) {
            $this->assertStringContainsString($callback, $flat);
        }

        // Незаданные ссылки в меню не попадают
        $this->assertStringNotContainsString('link_show_map', $flat);
        $this->assertStringNotContainsString('link_show_payment', $flat);

        // Ни одной ссылки в сообщении: после ротации у клиента не остаётся рабочего старого адреса
        $this->assertStringNotContainsString('example.com', $flat);
        $this->assertStringNotContainsString('"url"', $flat);
    }

    public function test_banned_user_gets_the_collapsed_menu_even_if_trusted(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true, 'is_banned' => true]);

        $this->assertFalse($botUser->hasMaterialsAccess());
    }

    public function test_keys_link_is_hidden_from_untrusted_user(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => false]);

        (new ShowRentalMaterial())->showKeys($this->callbackDto($botUser, 'access_show_keys'), $botUser);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && $r['show_alert'] === true);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_keys_link_is_sent_to_trusted_user(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        (new ShowRentalMaterial())->showKeys($this->callbackDto($botUser, 'access_show_keys'), $botUser);

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'sendMessage')) {
                return false;
            }

            $markup = json_decode($r['reply_markup'] ?? '[]', true);

            return ($markup['inline_keyboard'][0][0]['url'] ?? null) === 'https://example.com/keys';
        });
    }

    public function test_schedule_link_is_hidden_from_untrusted_user(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => false]);

        (new ShowRentalMaterial())->showSchedule($this->callbackDto($botUser, 'access_show_schedule'), $botUser);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && $r['show_alert'] === true);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_schedule_link_is_sent_to_trusted_user(): void
    {
        $botUser = $this->makeBotUser(['is_trusted' => true]);

        (new ShowRentalMaterial())->showSchedule($this->callbackDto($botUser, 'access_show_schedule'), $botUser);

        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'sendMessage')) {
                return false;
            }

            $markup = json_decode($r['reply_markup'] ?? '[]', true);

            return ($markup['inline_keyboard'][0][0]['url'] ?? null) === 'https://example.com/schedule'
                && str_contains($r['text'], 'График');
        });
    }

    public function test_offer_can_be_shown_again(): void
    {
        $botUser = $this->makeBotUser(['offer_accepted_at' => now()]);

        (new ShowRentalMaterial())->showOffer($this->callbackDto($botUser, 'offer_show'), $botUser);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendDocument')
            && !str_contains($r['reply_markup'] ?? '', 'offer_accept'));
    }

    public function test_contact_card_shows_offer_status(): void
    {
        $card = new SendContactMessage();

        $notAccepted = $card->createContactMessage(1, 'telegram');
        $this->assertStringContainsString('Оферта не принята', $notAccepted);

        $accepted = $card->createContactMessage(1, 'telegram', null, null, null, false, false, now());
        $this->assertStringContainsString('Оферта принята', $accepted);

        // Для других платформ оферта не применима
        $vk = $card->createContactMessage(1, 'vk');
        $this->assertStringNotContainsString('Оферта', $vk);
    }
}
