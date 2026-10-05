<?php

namespace Tests\Feature\Topic;

use App\Actions\Telegram\CloseTopic;
use App\Actions\Telegram\ReopenTopic;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

/**
 * Закрытие и повторное открытие обращений (топиков)
 */
class ReopenTopicTest extends TestCase
{
    use RefreshDatabase;

    /** Ответ Telegram на reopenForumTopic: ok | already_open */
    private string $reopenResult = 'ok';

    /** Пока тема "закрыта", отправка в неё сообщений даёт TOPIC_CLOSED */
    private bool $topicIsClosed = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'reopenForumTopic')) {
                if ($this->reopenResult === 'already_open') {
                    return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: TOPIC_NOT_MODIFIED'], 400);
                }

                $this->topicIsClosed = false;

                return Http::response(['ok' => true, 'result' => true]);
            }

            if (str_contains($url, 'sendMessage') && $this->topicIsClosed && !empty($request['message_thread_id'])) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: TOPIC_CLOSED'], 400);
            }

            return Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => random_int(1000, 999999),
                    'chat' => ['id' => $request['chat_id'] ?? 0, 'type' => 'supergroup'],
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
        ], $overrides));
    }

    /**
     * @return string[] имена методов Telegram в порядке вызова
     */
    private function calledMethods(): array
    {
        return array_map(
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)),
            Http::recorded()->all()
        );
    }

    private function groupDto(string $text, int $threadId): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => [
                'message_id' => time(),
                'message_thread_id' => $threadId,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => -1001234567890, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $text,
            ],
        ]));
    }

    public function test_closing_topic_marks_it_closed(): void
    {
        $botUser = $this->makeBotUser();

        (new CloseTopic())->execute($botUser);

        $this->assertTrue($botUser->fresh()->isTopicClosed());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'closeForumTopic'));
    }

    public function test_reopen_clears_closed_flag(): void
    {
        $botUser = $this->makeBotUser(['topic_closed_at' => now()]);

        $result = (new ReopenTopic())->execute($botUser);

        $this->assertSame(ReopenTopic::REOPENED, $result);
        $this->assertFalse($botUser->fresh()->isTopicClosed());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'reopenForumTopic')
            && (int)$r['message_thread_id'] === $botUser->topic_id);
    }

    public function test_reopen_of_already_open_topic_still_syncs_flag(): void
    {
        $this->reopenResult = 'already_open';
        $botUser = $this->makeBotUser(['topic_closed_at' => now()]);

        $this->assertSame(ReopenTopic::ALREADY_OPEN, (new ReopenTopic())->execute($botUser));
        $this->assertFalse($botUser->fresh()->isTopicClosed());
    }

    public function test_reopen_without_topic_fails_without_api_call(): void
    {
        $botUser = $this->makeBotUser(['topic_id' => null]);

        $this->assertSame(ReopenTopic::FAILED, (new ReopenTopic())->execute($botUser));
        $this->assertEmpty(Http::recorded());
    }

    public function test_reopen_command_replies_in_the_same_topic(): void
    {
        $botUser = $this->makeBotUser(['topic_closed_at' => now()]);

        (new ReopenTopic())->handleCommand($this->groupDto('/reopen', $botUser->topic_id), $botUser);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && (int)$r['message_thread_id'] === $botUser->topic_id
            && str_contains($r['text'], 'снова открыто'));
    }

    public function test_client_message_reopens_closed_topic_before_sending(): void
    {
        $dto = TelegramUpdateDtoMock::getDto();
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);
        $botUser->topic_id = 4242;
        $botUser->topic_closed_at = now();
        $botUser->save();
        $this->topicIsClosed = true;

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'text' => 'Здравствуйте, у меня вопрос',
        ]);

        (new SendTelegramMessageJob($botUser->id, $dto, $params, 'incoming'))->handle();

        // Тема открывается раньше любой отправки в неё
        $this->assertSame('reopenForumTopic', $this->calledMethods()[0]);
        $this->assertFalse($botUser->fresh()->isTopicClosed());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'тема открыта снова'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && ($r['text'] ?? '') === 'Здравствуйте, у меня вопрос'
            && (int)$r['message_thread_id'] === 4242);
    }

    public function test_open_topic_is_not_touched_when_client_writes(): void
    {
        $dto = TelegramUpdateDtoMock::getDto();
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);
        $botUser->topic_id = 4242;
        $botUser->save();

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'text' => 'Привет',
        ]);

        (new SendTelegramMessageJob($botUser->id, $dto, $params, 'incoming'))->handle();

        $this->assertNotContains('reopenForumTopic', $this->calledMethods());
    }

    public function test_topic_closed_error_triggers_reopen_even_without_flag(): void
    {
        // Тему закрыли вручную до появления флага: бот об этом не знает, узнаёт из ошибки Telegram
        $dto = TelegramUpdateDtoMock::getDto();
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);
        $botUser->topic_id = 4242;
        $botUser->save();
        $this->topicIsClosed = true;

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'text' => 'Привет',
        ]);

        (new SendTelegramMessageJob($botUser->id, $dto, $params, 'incoming'))->handle();

        $this->assertContains('reopenForumTopic', $this->calledMethods());
    }

    public function test_dto_detects_topic_service_messages(): void
    {
        $make = fn (string $key) => TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => [
                'message_id' => time(),
                'message_thread_id' => 55,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => -1001234567890, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                $key => ['dummy' => 1],
            ],
        ]));

        $closed = $make('forum_topic_closed');
        $this->assertTrue($closed->topicClosedStatus);
        $this->assertFalse($closed->topicReopenedStatus);

        $reopened = $make('forum_topic_reopened');
        $this->assertTrue($reopened->topicReopenedStatus);
        $this->assertFalse($reopened->topicClosedStatus);
    }
}
