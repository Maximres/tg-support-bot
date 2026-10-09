<?php

namespace Tests\Feature\Jobs;

use App\Actions\Telegram\PinContactCard;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendContactMessageWithCallbackJob;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Контактная карточка клиента закреплена в его теме
 */
class ContactCardPinTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 777, 'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time()]])]);
    }

    private function recorded(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    private function sendCard(BotUser $botUser): void
    {
        (new SendContactMessageWithCallbackJob($botUser->id, TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => self::GROUP_ID,
            'message_thread_id' => $botUser->topic_id,
            'text' => 'card',
        ])))->handle();
    }

    public function test_new_card_is_pinned_in_the_topic(): void
    {
        $botUser = BotUser::create(['chat_id' => 1001, 'platform' => 'telegram', 'topic_id' => 4242]);

        $this->sendCard($botUser);

        $pins = $this->recorded('pinChatMessage');
        $this->assertCount(1, $pins);
        $this->assertSame(777, (int)$pins[0][0]['message_id']);
        $this->assertSame(0, count($this->recorded('unpinChatMessage')));
        $this->assertSame(777, (int)$botUser->fresh()->contact_info_message_id);
    }

    public function test_replaced_card_unpins_the_old_one(): void
    {
        $botUser = BotUser::create(['chat_id' => 1002, 'platform' => 'telegram', 'topic_id' => 4242, 'contact_info_message_id' => 500]);

        $this->sendCard($botUser);

        $unpins = $this->recorded('unpinChatMessage');
        $this->assertCount(1, $unpins);
        $this->assertSame(500, (int)$unpins[0][0]['message_id']);
        $this->assertSame(777, (int)$this->recorded('pinChatMessage')[0][0]['message_id']);
    }

    public function test_nothing_is_pinned_without_a_topic_or_card(): void
    {
        $botUser = BotUser::create(['chat_id' => 1003, 'platform' => 'telegram']);

        $this->assertFalse((new PinContactCard())->execute($botUser));
        $this->assertCount(0, Http::recorded());
    }
}
