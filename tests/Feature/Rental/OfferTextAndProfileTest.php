<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\HandleOfferTextCommand;
use App\Actions\Telegram\SendOfferMessage;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;
use App\Services\Rental\OfferText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Текст договора, который владелец правит из Telegram, и оформление бота для клиентов
 */
class OfferTextAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID, 'rental.offer_document' => null]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]]);
        });
    }

    private function groupMessage(string $text, array $entities = []): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => array_filter([
                'message_id' => random_int(1, 1000),
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_ID, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $text,
                'entities' => $entities ?: null,
            ]),
        ]));
    }

    private function sent(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    public function test_default_offer_text_points_to_the_button_and_fits_the_caption(): void
    {
        $this->assertSame(__('messages.offer_message_text'), OfferText::html());
        $this->assertLessThanOrEqual(1000, mb_strlen(strip_tags(OfferText::html())));
        $this->assertStringContainsString('Нажмите кнопку «Принимаю условия»', OfferText::html());
        $this->assertStringContainsString('договор с ИП напишите об этом нам', OfferText::html());
    }

    public function test_admin_sets_the_text_with_telegram_formatting_after_the_command(): void
    {
        $command = '/set_offer_text';
        $text = $command . ' Договор: условия по ссылке';

        (new HandleOfferTextCommand())->execute($this->groupMessage($text, [
            ['type' => 'bot_command', 'offset' => 0, 'length' => 15],
            ['type' => 'bold', 'offset' => 16, 'length' => 7],
            ['type' => 'text_link', 'offset' => 36, 'length' => 6, 'url' => 'https://example.com/offer'],
        ]), $command);

        $this->assertSame('<b>Договор</b>: условия по <a href="https://example.com/offer">ссылке</a>', OfferText::html());
        $this->assertTrue(OfferText::isCustom());

        // Админ сразу видит результат
        $texts = array_map(fn ($pair) => $pair[0]['text'], $this->sent('sendMessage'));
        $this->assertContains(OfferText::html(), $texts);
    }

    public function test_text_sent_in_reply_to_the_panel_prompt_has_no_command_prefix(): void
    {
        (new HandleOfferTextCommand())->execute($this->groupMessage('Новый текст', [['type' => 'italic', 'offset' => 0, 'length' => 5]]), '/set_offer_text');

        $this->assertSame('<i>Новый</i> текст', OfferText::html());
    }

    public function test_default_resets_and_long_or_empty_text_is_refused(): void
    {
        OfferText::set('Старый текст');

        (new HandleOfferTextCommand())->execute($this->groupMessage('/set_offer_text ' . str_repeat('я', 1001)), '/set_offer_text');
        $this->assertSame('Старый текст', OfferText::html());

        (new HandleOfferTextCommand())->execute($this->groupMessage('/set_offer_text'), '/set_offer_text');
        $this->assertSame('Старый текст', OfferText::html());

        (new HandleOfferTextCommand())->execute($this->groupMessage('/set_offer_text default'), '/set_offer_text');
        $this->assertFalse(OfferText::isCustom());
        $this->assertSame(__('messages.offer_message_text'), OfferText::html());
    }

    public function test_only_admins_can_change_the_text(): void
    {
        $this->chatMemberStatus = 'member';

        (new HandleOfferTextCommand())->execute($this->groupMessage('/set_offer_text Взлом'), '/set_offer_text');

        $this->assertFalse(OfferText::isCustom());
    }

    public function test_clients_get_the_custom_text_with_the_offer(): void
    {
        OfferText::set('<b>Свой текст</b> договора');
        OfferDocument::set('FILE_ID', 'offer.pdf');
        $botUser = BotUser::create(['chat_id' => 5550001, 'platform' => 'telegram', 'topic_id' => 1]);

        (new SendOfferMessage())->execute($botUser);

        $documents = $this->sent('sendDocument');
        $this->assertCount(1, $documents);
        $this->assertSame('<b>Свой текст</b> договора', $documents[0][0]['caption']);
    }

    public function test_show_offer_text_command_shows_the_current_text(): void
    {
        (new HandleOfferTextCommand())->execute($this->groupMessage('/show_offer_text'), '/show_offer_text');

        $texts = array_map(fn ($pair) => $pair[0]['text'], $this->sent('sendMessage'));
        $this->assertContains(__('messages.offer_message_text'), $texts);
    }

    public function test_bot_profile_command_sets_the_descriptions_clients_see(): void
    {
        $this->artisan('telegram:set-profile')->assertSuccessful();

        $descriptions = $this->sent('setMyDescription');
        $this->assertCount(1, $descriptions);
        $this->assertSame(__('messages.bot_profile.description'), $descriptions[0][0]['description']);
        $this->assertLessThanOrEqual(512, mb_strlen($descriptions[0][0]['description']));

        $short = $this->sent('setMyShortDescription');
        $this->assertCount(1, $short);
        $this->assertLessThanOrEqual(120, mb_strlen($short[0][0]['short_description']));

        // Название не меняется без явной просьбы
        $this->assertCount(0, $this->sent('setMyName'));

        $this->artisan('telegram:set-profile', ['--name' => true])->assertSuccessful();
        $this->assertCount(1, $this->sent('setMyName'));
        $this->assertLessThanOrEqual(64, mb_strlen(__('messages.bot_profile.name')));
    }
}
