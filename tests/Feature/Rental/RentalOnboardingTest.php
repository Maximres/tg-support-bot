<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\AcceptOffer;
use App\Actions\Telegram\SendAccessMessage;
use App\Actions\Telegram\SendContactMessage;
use App\Actions\Telegram\SendOfferMessage;
use App\Actions\Telegram\ShowRentalMaterial;
use App\Models\BotUser;
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
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '+375291234567'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pinChatMessage'));
    }

    public function test_accepting_offer_twice_does_not_resend_menu(): void
    {
        $botUser = $this->makeBotUser();
        $dto = $this->callbackDto($botUser, 'offer_accept');

        (new AcceptOffer())->execute($dto, $botUser);
        $firstAcceptedAt = $botUser->fresh()->offer_accepted_at;

        (new AcceptOffer())->execute($dto, $botUser->fresh());

        $this->assertSame(1, $this->sentCount('pinChatMessage'));
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

    public function test_menu_contains_only_configured_links(): void
    {
        $keyboard = (new SendAccessMessage())->getKeyboard();
        $flat = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Общая страница инструкций — отдельной строкой сверху
        $this->assertCount(1, $keyboard[0]);
        $this->assertSame('https://example.com/hub', $keyboard[0][0]['url']);

        $this->assertStringContainsString('https://example.com/cabinets', $flat);
        $this->assertStringContainsString('https://example.com/wifi', $flat);
        $this->assertStringContainsString('access_show_safe', $flat);
        $this->assertStringContainsString('access_show_building', $flat);
        $this->assertStringContainsString('access_show_org_link', $flat);
        $this->assertStringContainsString('offer_show', $flat);

        // Незаданные ссылки в меню не попадают; ключи не светятся ссылкой, только через callback
        $this->assertStringNotContainsString('Как добраться', $flat);
        $this->assertStringNotContainsString('Оплата', $flat);
        $this->assertStringContainsString('access_show_keys', $flat);
        $this->assertStringNotContainsString('https://example.com/keys', $flat);
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

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['reply_markup'] ?? '', 'https://example.com/keys'));
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
