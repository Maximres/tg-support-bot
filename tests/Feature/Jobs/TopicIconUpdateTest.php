<?php

namespace Tests\Feature\Jobs;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

/**
 * Значок темы (💬/✅) после отправки сообщения клиенту и от клиента
 */
class TopicIconUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID]);

        $this->botUser = BotUser::create([
            'chat_id' => random_int(1, 2_000_000_000),
            'platform' => 'telegram',
            'topic_id' => 4242,
            'is_banned' => false,
        ]);
    }

    private function sendOutgoing(array $params = []): void
    {
        (new SendTelegramMessageJob(
            $this->botUser->id,
            TelegramUpdateDtoMock::getDto(),
            TGTextMessageDto::from(array_merge([
                'methodQuery' => 'sendMessage',
                'chat_id' => $this->botUser->chat_id,
                'text' => 'привет',
            ], $params)),
            'outgoing'
        ))->handle();
    }

    private function sentTo(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    private function okMessage(): array
    {
        return ['ok' => true, 'result' => ['message_id' => random_int(1000, 99999), 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]];
    }

    public function test_reply_to_client_sets_the_outgoing_icon(): void
    {
        Http::fake(['*' => Http::response($this->okMessage())]);

        $this->sendOutgoing();

        $edits = $this->sentTo('editForumTopic');
        $this->assertCount(1, $edits);
        $this->assertSame(__('icons.outgoing'), $edits[0][0]['icon_custom_emoji_id']);
        $this->assertSame(4242, (int)$edits[0][0]['message_thread_id']);
    }

    public function test_quick_back_and_forth_changes_the_icon_both_times(): void
    {
        Http::fake(['*' => Http::response($this->okMessage())]);

        // Клиент написал и тут же получил ответ — раньше вторая смена пропускалась из-за блокировки на 5 секунд
        $this->sendOutgoing();
        $this->sendOutgoing();
        (new SendTelegramMessageJob(
            $this->botUser->id,
            TelegramUpdateDtoMock::getDto(),
            TGTextMessageDto::from(['methodQuery' => 'sendMessage', 'chat_id' => self::GROUP_ID, 'message_thread_id' => 4242, 'text' => 'hi']),
            'incoming'
        ))->handle();

        $icons = array_map(fn ($pair) => $pair[0]['icon_custom_emoji_id'], $this->sentTo('editForumTopic'));
        $this->assertSame([__('icons.outgoing'), __('icons.outgoing'), __('icons.incoming')], $icons);
    }

    public function test_topic_not_modified_is_a_success_for_the_icon_update(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: TOPIC_NOT_MODIFIED'], 400)]);

        $result = (new SendTelegramSimpleQueryJob(TGTextMessageDto::from([
            'methodQuery' => 'editForumTopic',
            'chat_id' => self::GROUP_ID,
            'message_thread_id' => 4242,
            'icon_custom_emoji_id' => __('icons.outgoing'),
        ])))->handle();

        $this->assertTrue($result);
    }

    public function test_markdown_error_is_retried_as_plain_text_and_the_icon_is_updated(): void
    {
        Http::fake([
            '*/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities: Can't find end of the entity"], 400)
                ->push($this->okMessage()),
            '*' => Http::response($this->okMessage()),
        ]);

        $this->sendOutgoing(['text' => 'https://example.com/a\\_b', 'parse_mode' => 'MarkdownV2']);

        $sends = $this->sentTo('sendMessage');
        $this->assertCount(2, $sends);
        $this->assertSame('MarkdownV2', $sends[0][0]['parse_mode']);
        $this->assertArrayNotHasKey('parse_mode', $sends[1][0]->data());
        $this->assertSame('https://example.com/a_b', $sends[1][0]['text']);

        $this->assertCount(1, $this->sentTo('editForumTopic'));
        $this->assertSame(1, Message::where('bot_user_id', $this->botUser->id)->count());
    }

    public function test_undeliverable_reply_leaves_a_note_in_the_topic(): void
    {
        Http::fake([
            '*/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)
                ->push($this->okMessage()),
            '*' => Http::response($this->okMessage()),
        ]);

        $this->sendOutgoing();

        // Значок не меняется (ответ не ушёл), но админ видит причину в теме
        $this->assertCount(0, $this->sentTo('editForumTopic'));

        $notes = array_filter(
            $this->sentTo('sendMessage'),
            fn ($pair) => (int)($pair[0]['message_thread_id'] ?? 0) === 4242 && str_contains($pair[0]['text'] ?? '', 'не доставлено')
        );
        $this->assertCount(1, $notes);
    }
}
