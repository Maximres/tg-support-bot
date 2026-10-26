<?php

namespace Tests\Feature\Jobs;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Models\Message;
use App\Services\Tg\TgEditMessageService;
use App\Services\Tg\TgMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Сообщения клиента пересылаются в его тему: в группе видны имя и фото профиля клиента
 */
class ClientForwardTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private const CLIENT_CHAT = 5550001;

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID]);
    }

    private function client(array $overrides = []): BotUser
    {
        return BotUser::create(array_merge([
            'chat_id' => self::CLIENT_CHAT,
            'platform' => 'telegram',
            'topic_id' => 4242,
            'full_name' => 'Иванов Иван Иванович',
            'phone_number' => '+375291234567',
            'email' => 'ivanov@example.com',
        ], $overrides));
    }

    private function privateUpdate(array $content, string $type = 'message'): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => 777,
            $type => array_merge([
                'message_id' => 321,
                'from' => ['id' => self::CLIENT_CHAT, 'is_bot' => false, 'first_name' => 'Иван'],
                'chat' => ['id' => self::CLIENT_CHAT, 'type' => 'private', 'first_name' => 'Иван'],
                'date' => time(),
            ], $content),
        ]));
    }

    private function sent(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    /** Копии сообщения в теме (служебная проверка существования темы тоже шлёт sendMessage — её не считаем) */
    private function copies(): array
    {
        return array_values(array_filter($this->sent('sendMessage'), fn ($pair) => ($pair[0]['text'] ?? '') === 'копия'));
    }

    private function sendJob(BotUser $botUser, TelegramUpdateDto $update, bool $forwardFirst = true): void
    {
        (new SendTelegramMessageJob(
            $botUser->id,
            $update,
            TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => self::GROUP_ID,
                'message_thread_id' => $botUser->topic_id,
                'text' => 'копия',
                'typeSource' => 'private',
            ]),
            'incoming',
            null,
            $forwardFirst
        ))->handle();
    }

    public function test_client_message_is_forwarded_into_the_topic(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 901, 'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time()]])]);
        $botUser = $this->client();

        $this->sendJob($botUser, $this->privateUpdate(['text' => 'Здравствуйте']));

        $forwards = $this->sent('forwardMessage');
        $this->assertCount(1, $forwards);
        $this->assertSame(self::GROUP_ID, (int)$forwards[0][0]['chat_id']);
        $this->assertSame(4242, (int)$forwards[0][0]['message_thread_id']);
        $this->assertSame(self::CLIENT_CHAT, (int)$forwards[0][0]['from_chat_id']);
        $this->assertSame(321, (int)$forwards[0][0]['message_id']);

        // Копия не отправляется, связь «сообщение клиента → сообщение в теме» сохранена, значок обновлён
        $this->assertCount(0, $this->copies());
        $message = Message::first();
        $this->assertSame(321, (int)$message->from_id);
        $this->assertSame(901, (int)$message->to_id);
        $this->assertCount(1, $this->sent('editForumTopic'));
    }

    public function test_copy_is_sent_when_forwarding_is_not_possible(): void
    {
        Http::fake([
            '*/forwardMessage' => Http::response(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: message can't be forwarded"], 400),
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 902, 'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time()]]),
        ]);
        $botUser = $this->client();

        $this->sendJob($botUser, $this->privateUpdate(['text' => 'Защищённое сообщение']));

        $this->assertCount(1, $this->sent('forwardMessage'));
        $this->assertCount(1, $this->copies());
        $this->assertSame(902, (int)Message::first()->to_id);
    }

    public function test_without_the_flag_the_copy_is_sent_as_before(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 903, 'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time()]])]);
        $botUser = $this->client();

        $this->sendJob($botUser, $this->privateUpdate(['text' => 'Привет']), false);

        $this->assertCount(0, $this->sent('forwardMessage'));
        $this->assertCount(1, $this->copies());
    }

    public function test_service_marks_registered_clients_messages_for_forwarding_but_not_contacts(): void
    {
        Queue::fake();
        $this->client();

        (new TgMessageService($this->privateUpdate(['text' => 'Вопрос по аренде'])))->handleUpdate();
        (new TgMessageService($this->privateUpdate(['contact' => ['phone_number' => '+375291234567', 'first_name' => 'Иван', 'user_id' => self::CLIENT_CHAT]])))->handleUpdate();

        /** @phpstan-ignore-next-line */
        $jobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $flags = array_map(fn ($job) => $job['job']->forwardFirst, $jobs);

        $this->assertSame([true], array_slice($flags, 0, 1));
        $this->assertContains(false, $flags);
    }

    public function test_forwarding_can_be_disabled_by_config(): void
    {
        Queue::fake();
        config(['traffic_source.settings.telegram.forward_client_messages' => false]);
        $this->client();

        (new TgMessageService($this->privateUpdate(['text' => 'Вопрос по аренде'])))->handleUpdate();

        /** @phpstan-ignore-next-line */
        $jobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertFalse($jobs[0]['job']->forwardFirst);
    }

    public function test_edited_client_message_is_shown_again_with_a_note(): void
    {
        Queue::fake();
        $this->client();

        (new TgEditMessageService($this->privateUpdate(['text' => 'Исправленный текст', 'edit_date' => time()], 'edited_message')))->handleUpdate();

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $methods = array_map(fn ($job) => $job['job']->queryParams->methodQuery, $pushed);

        $this->assertSame(['sendMessage', 'forwardMessage'], $methods);
        $this->assertStringContainsString('изменил', $pushed[0]['job']->queryParams->text);
        $this->assertSame(321, (int)$pushed[1]['job']->queryParams->message_id);
        $this->assertSame(self::CLIENT_CHAT, (int)$pushed[1]['job']->queryParams->from_chat_id);
    }
}
