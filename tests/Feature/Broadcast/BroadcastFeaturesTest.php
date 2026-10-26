<?php

namespace Tests\Feature\Broadcast;

use App\Actions\Telegram\HandleBroadcastCommand;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\Broadcast\SendBroadcastMessageJob;
use App\Models\BotUser;
use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use App\Services\Broadcast\BroadcastManager;
use App\Services\Broadcast\BroadcastMessageService;
use App\Services\Broadcast\BroadcastSettings;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Массовая рассылка: ссылки в тексте, длинные подписи, альбомы, защита от повторов,
 * учёт отправленного, удаление и исправление у клиентов
 */
class BroadcastFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private const TOPIC_ID = 178;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'traffic_source.settings.telegram.group_id' => self::GROUP_ID,
            'traffic_source.settings.telegram.token' => '111:TESTTOKEN',
            'traffic_source.settings.telegram.broadcast_album_wait' => 0,
        ]);
    }

    private function update(array $content, int $messageId, string $type = 'message', array $extra = []): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', array_merge([
            'update_id' => 9000 + $messageId,
            $type => array_merge([
                'message_id' => $messageId,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_ID, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'message_thread_id' => self::TOPIC_ID,
                'date' => time(),
            ], $content),
        ], $extra)));
    }

    private function client(int $chatId = 1001): BotUser
    {
        return BotUser::create(['chat_id' => $chatId, 'platform' => 'telegram', 'topic_id' => $chatId]);
    }

    /** @return SendBroadcastMessageJob[] */
    private function batchedJobs(): array
    {
        $jobs = [];

        Bus::assertBatched(function (PendingBatch $batch) use (&$jobs) {
            $jobs = array_merge($jobs, $batch->jobs->all());

            return true;
        });

        return $jobs;
    }

    private function sent(string $method, ?callable $filter = null): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method && ($filter === null || $filter($pair[0]))
        ));
    }

    private function fakeTelegram(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            if (str_contains($request->url(), 'sendMediaGroup')) {
                return Http::response(['ok' => true, 'result' => [['message_id' => 501], ['message_id' => 502]]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 9999), 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]]);
        });
    }

    // ---- содержимое рассылки

    public function test_hidden_links_and_formatting_are_kept(): void
    {
        Bus::fake();
        $this->client();

        (new BroadcastMessageService())->handle($this->update([
            'text' => 'Пишите в ЧАТ-ПОДДЕРЖКИ сегодня',
            'entities' => [
                ['type' => 'text_link', 'offset' => 9, 'length' => 13, 'url' => 'https://t.me/Garetski_Rent_Bot'],
                ['type' => 'bold', 'offset' => 23, 'length' => 7],
            ],
        ], 10));

        $main = $this->batchedJobs()[0]->queryParams;

        $this->assertSame('sendMessage', $main->methodQuery);
        $this->assertSame('html', $main->parse_mode);
        $this->assertSame('Пишите в <a href="https://t.me/Garetski_Rent_Bot">ЧАТ-ПОДДЕРЖКИ</a> <b>сегодня</b>', $main->text);
    }

    public function test_short_caption_is_kept_with_formatting(): void
    {
        Bus::fake();
        $this->client();

        (new BroadcastMessageService())->handle($this->update([
            'photo' => [['file_id' => 'SMALL', 'width' => 10, 'height' => 10], ['file_id' => 'BIG', 'width' => 100, 'height' => 100]],
            'caption' => 'Смотрите график',
            'caption_entities' => [['type' => 'text_link', 'offset' => 9, 'length' => 6, 'url' => 'https://example.com/g']],
        ], 11));

        $job = $this->batchedJobs()[0];

        $this->assertSame('sendPhoto', $job->queryParams->methodQuery);
        $this->assertSame('BIG', $job->queryParams->photo);
        $this->assertSame('Смотрите <a href="https://example.com/g">график</a>', $job->queryParams->caption);
        $this->assertSame([], $job->followUps);
    }

    public function test_long_caption_is_sent_as_a_separate_text_and_not_cut(): void
    {
        Bus::fake();
        $this->client();

        $caption = str_repeat("Строка подписи для проверки длинного текста.\n", 40) . 'Конец';

        (new BroadcastMessageService())->handle($this->update([
            'photo' => [['file_id' => 'BIG', 'width' => 100, 'height' => 100]],
            'caption' => $caption,
        ], 12));

        $job = $this->batchedJobs()[0];

        $this->assertNull($job->queryParams->caption);
        $this->assertCount(1, $job->followUps);
        $this->assertStringContainsString('Конец', $job->followUps[0]->text);
        $this->assertGreaterThan(1024, mb_strlen($job->followUps[0]->text));
    }

    public function test_long_text_is_split_into_several_messages(): void
    {
        Bus::fake();
        $this->client();

        $paragraph = str_repeat('слово ', 400);

        (new BroadcastMessageService())->handle($this->update(['text' => $paragraph . "\n\n" . $paragraph], 13));

        $job = $this->batchedJobs()[0];

        $this->assertGreaterThan(0, count($job->followUps));
        foreach (array_merge([$job->queryParams], $job->followUps) as $dto) {
            $this->assertLessThanOrEqual(4096, mb_strlen($dto->text));
        }
    }

    public function test_album_is_sent_as_one_media_group_with_the_caption_on_the_first_item(): void
    {
        Bus::fake();
        $this->client();

        $part = fn (string $fileId, ?string $caption = null) => array_filter([
            'media_group_id' => 'ALBUM1',
            'photo' => [['file_id' => $fileId, 'width' => 100, 'height' => 100]],
            'caption' => $caption,
        ]);

        // Остальные части альбома уже пришли, пока ведущая ждала
        $key = 'broadcast_album_' . self::GROUP_ID . '_' . md5('ALBUM1');
        Cache::put($key . '_parts', [
            21 => ['message' => $part('P2'), 'caption' => null, 'entities' => null],
            22 => ['message' => $part('P3'), 'caption' => null, 'entities' => null],
        ], 300);

        (new BroadcastMessageService())->handle($this->update($part('P1', 'Подпись альбома'), 20));

        $jobs = $this->batchedJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('sendMediaGroup', $jobs[0]->queryParams->methodQuery);

        $media = $jobs[0]->mediaGroup;
        $this->assertSame(['P1', 'P2', 'P3'], array_column($media, 'media'));
        $this->assertSame('Подпись альбома', $media[0]['caption']);
        $this->assertArrayNotHasKey('caption', $media[1]);

        $this->assertSame([20, 21, 22], Broadcast::first()->source_message_ids);
    }

    // ---- безопасность

    public function test_the_same_message_is_never_broadcast_twice(): void
    {
        Bus::fake();
        $this->client();

        $update = $this->update(['text' => 'Привет всем'], 30);

        (new BroadcastMessageService())->handle($update);
        (new BroadcastMessageService())->handle($update);
        (new BroadcastMessageService())->handle($this->update(['text' => 'Привет всем'], 30));

        Bus::assertBatchCount(1);
        $this->assertSame(1, Broadcast::count());
    }

    public function test_commands_and_service_messages_are_not_broadcast(): void
    {
        Bus::fake();
        $this->client();

        (new BroadcastMessageService())->handle($this->update(['text' => '/panel'], 31));
        (new BroadcastMessageService())->handle($this->update(['text' => '/broadcast_delete last'], 32));
        (new BroadcastMessageService())->handle($this->update(['forum_topic_edited' => ['name' => 'Рассылка']], 33));

        Bus::assertNothingBatched();
        $this->assertSame(0, Broadcast::count());
    }

    public function test_switched_off_broadcast_sends_nothing_and_tells_the_admins(): void
    {
        Bus::fake();
        $this->fakeTelegram();
        $this->client();
        BroadcastSettings::setEnabled(false);

        (new BroadcastMessageService())->handle($this->update(['text' => 'Не должно уйти'], 34));

        Bus::assertNothingBatched();
        $this->assertSame(0, Broadcast::count());
    }

    // ---- отправка клиенту и учёт

    public function test_sent_messages_are_recorded_including_follow_ups(): void
    {
        $this->fakeTelegram();
        $user = $this->client();
        $broadcast = Broadcast::create(['source_chat_id' => self::GROUP_ID, 'source_message_id' => 40, 'source_message_ids' => [40], 'kind' => 'media', 'has_follow_up' => true]);

        $main = TGTextMessageDto::from(['methodQuery' => 'sendPhoto', 'chat_id' => $user->chat_id, 'photo' => 'BIG', 'token' => '111:TESTTOKEN']);
        $followUp = TGTextMessageDto::from(['methodQuery' => 'sendMessage', 'chat_id' => $user->chat_id, 'text' => 'Длинная подпись', 'parse_mode' => 'html', 'token' => '111:TESTTOKEN']);

        (new SendBroadcastMessageJob($user->id, $main, $broadcast->id, [$followUp]))->handle();

        $record = BroadcastMessage::first();
        $this->assertCount(2, $record->message_ids);
        $this->assertSame($user->chat_id, (int)$record->chat_id);
        $this->assertCount(1, $this->sent('sendPhoto'));
        $this->assertCount(1, $this->sent('sendMessage'));
    }

    public function test_album_send_records_all_message_ids(): void
    {
        $this->fakeTelegram();
        $user = $this->client();
        $broadcast = Broadcast::create(['source_chat_id' => self::GROUP_ID, 'source_message_id' => 50, 'source_message_ids' => [50, 51], 'kind' => 'album']);

        $dto = TGTextMessageDto::from(['methodQuery' => 'sendMediaGroup', 'chat_id' => $user->chat_id, 'token' => '111:TESTTOKEN']);

        (new SendBroadcastMessageJob($user->id, $dto, $broadcast->id, [], [['type' => 'photo', 'media' => 'A'], ['type' => 'photo', 'media' => 'B']]))->handle();

        $this->assertSame([501, 502], BroadcastMessage::first()->message_ids);
    }

    public function test_markdown_error_is_retried_as_plain_text_and_blocked_clients_are_not_recorded(): void
    {
        Http::fake([
            '*/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities: Unsupported start tag"], 400)
                ->push(['ok' => true, 'result' => ['message_id' => 77, 'chat' => ['id' => 1, 'type' => 'private'], 'date' => time()]])
                ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403),
        ]);

        $user = $this->client(1001);
        $blocked = $this->client(1002);
        $broadcast = Broadcast::create(['source_chat_id' => self::GROUP_ID, 'source_message_id' => 60, 'source_message_ids' => [60], 'kind' => 'text']);
        $dto = fn (int $chatId) => TGTextMessageDto::from(['methodQuery' => 'sendMessage', 'chat_id' => $chatId, 'text' => '<b>Привет</b>', 'parse_mode' => 'html', 'token' => '111:TESTTOKEN']);

        (new SendBroadcastMessageJob($user->id, $dto(1001), $broadcast->id))->handle();
        (new SendBroadcastMessageJob($blocked->id, $dto(1002), $broadcast->id))->handle();

        $sends = $this->sent('sendMessage');
        $this->assertCount(3, $sends);
        $this->assertArrayNotHasKey('parse_mode', $sends[1][0]->data());
        $this->assertSame('Привет', $sends[1][0]['text']);

        $this->assertSame(1, BroadcastMessage::count());
        $this->assertSame([77], BroadcastMessage::first()->message_ids);
    }

    // ---- удаление и правка у клиентов

    private function recordedBroadcast(string $kind = 'text', bool $followUp = false, array $ids = [[801], [802]]): Broadcast
    {
        $broadcast = Broadcast::create([
            'source_chat_id' => self::GROUP_ID,
            'source_message_id' => 70,
            'source_message_ids' => [70],
            'kind' => $kind,
            'has_follow_up' => $followUp,
            'status' => Broadcast::STATUS_SENT,
        ]);

        foreach ($ids as $i => $messageIds) {
            BroadcastMessage::create(['broadcast_id' => $broadcast->id, 'bot_user_id' => $i + 1, 'chat_id' => 2000 + $i, 'message_ids' => $messageIds]);
        }

        return $broadcast;
    }

    public function test_broadcast_delete_removes_the_messages_from_every_client(): void
    {
        $this->fakeTelegram();
        $this->recordedBroadcast();

        $command = $this->update(['text' => '/broadcast_delete', 'reply_to_message' => [
            'message_id' => 70, 'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time(), 'text' => 'Привет',
        ]], 71);

        (new HandleBroadcastCommand())->execute($command, '/broadcast_delete');

        $deletes = $this->sent('deleteMessage');
        $this->assertCount(2, $deletes);
        $this->assertEqualsCanonicalizing([801, 802], array_map(fn ($pair) => (int)$pair[0]['message_id'], $deletes));
        $this->assertSame(Broadcast::STATUS_DELETED, Broadcast::first()->status);

        // Повторно ничего не удаляется
        (new HandleBroadcastCommand())->execute($command, '/broadcast_delete');
        $this->assertCount(2, $this->sent('deleteMessage'));
    }

    public function test_broadcast_delete_last_and_admin_check(): void
    {
        $this->fakeTelegram();
        $this->recordedBroadcast();

        $this->chatMemberStatus = 'member';
        (new HandleBroadcastCommand())->execute($this->update(['text' => '/broadcast_delete last'], 72), '/broadcast_delete');
        $this->assertCount(0, $this->sent('deleteMessage'));

        $this->chatMemberStatus = 'administrator';
        (new HandleBroadcastCommand())->execute($this->update(['text' => '/broadcast_delete last'], 73), '/broadcast_delete');
        $this->assertCount(2, $this->sent('deleteMessage'));
    }

    public function test_switch_commands_toggle_the_broadcast(): void
    {
        $this->fakeTelegram();

        (new HandleBroadcastCommand())->execute($this->update(['text' => '/broadcast_off'], 74), '/broadcast_off');
        $this->assertFalse(BroadcastSettings::enabled());

        (new HandleBroadcastCommand())->execute($this->update(['text' => '/broadcast_on'], 75), '/broadcast_on');
        $this->assertTrue(BroadcastSettings::enabled());
    }

    public function test_editing_the_source_message_edits_every_clients_copy(): void
    {
        $this->fakeTelegram();
        $this->recordedBroadcast();

        $report = (new BroadcastManager())->applyEdit($this->update([
            'text' => 'Исправленный текст',
            'entities' => [['type' => 'text_link', 'offset' => 0, 'length' => 12, 'url' => 'https://example.com']],
        ], 70, 'edited_message'));

        $edits = $this->sent('editMessageText');
        $this->assertCount(2, $edits);
        $this->assertSame('<a href="https://example.com">Исправленный</a> текст', $edits[0][0]['text']);
        $this->assertEqualsCanonicalizing([801, 802], array_map(fn ($pair) => (int)$pair[0]['message_id'], $edits));
        $this->assertStringContainsString('исправлена', $report);
        $this->assertSame(1, Broadcast::count());
    }

    public function test_editing_a_caption_edits_the_caption_and_the_split_text_part(): void
    {
        $this->fakeTelegram();
        $this->recordedBroadcast('media', false, [[801]]);

        (new BroadcastManager())->applyEdit($this->update(['caption' => 'Новая подпись'], 70, 'edited_message'));

        $this->assertCount(1, $this->sent('editMessageCaption'));
        $this->assertSame('Новая подпись', $this->sent('editMessageCaption')[0][0]['caption']);

        // Длинная подпись ушла отдельным текстом (второе сообщение): правится именно он, картинка не трогается
        Broadcast::query()->delete();
        $this->recordedBroadcast('media', true, [[801, 802]]);

        (new BroadcastManager())->applyEdit($this->update(['caption' => 'Новая длинная подпись'], 70, 'edited_message'));

        $edit = $this->sent('editMessageText');
        $this->assertCount(1, $edit);
        $this->assertSame(802, (int)$edit[0][0]['message_id']);
    }

    public function test_edit_is_refused_when_the_number_of_parts_changes_or_the_broadcast_is_deleted(): void
    {
        $this->fakeTelegram();
        $this->recordedBroadcast('text', true, [[801, 802]]);

        $report = (new BroadcastManager())->applyEdit($this->update(['text' => 'Короткий текст'], 70, 'edited_message'));

        $this->assertStringContainsString('другое число сообщений', $report);
        $this->assertCount(0, $this->sent('editMessageText'));

        Broadcast::query()->update(['status' => Broadcast::STATUS_DELETED]);
        $this->assertStringContainsString('уже удалена', (new BroadcastManager())->applyEdit($this->update(['text' => 'Текст'], 70, 'edited_message')));
    }

    public function test_editing_an_unrelated_message_does_nothing(): void
    {
        $this->fakeTelegram();
        $this->recordedBroadcast();

        $this->assertNull((new BroadcastManager())->applyEdit($this->update(['text' => 'Другое сообщение'], 999, 'edited_message')));
        $this->assertCount(0, $this->sent('editMessageText'));
    }
}
