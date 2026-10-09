<?php

namespace Tests\Feature\Broadcast;

use App\DTOs\TelegramUpdateDto;
use App\Jobs\Broadcast\SendBroadcastMessageJob;
use App\Models\BotUser;
use App\Services\Broadcast\BroadcastMessageService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Массовая рассылка: стикеры (и другие типы) уходят всем активным клиентам
 */
class BroadcastStickerTest extends TestCase
{
    use RefreshDatabase;

    private function update(array $content, int $updateId): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => $updateId,
            'message' => array_merge([
                'message_id' => $updateId,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => -1001234567890, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'message_thread_id' => 178,
                'date' => time(),
            ], $content),
        ]));
    }

    private function batchedJobs(): array
    {
        $jobs = [];

        Bus::assertBatched(function (PendingBatch $batch) use (&$jobs) {
            $jobs = array_merge($jobs, $batch->jobs->all());

            return true;
        });

        return $jobs;
    }

    public function test_sticker_is_broadcast_to_active_clients_only(): void
    {
        Bus::fake();
        Cache::flush();

        $active = BotUser::create(['chat_id' => 1001, 'platform' => 'telegram', 'topic_id' => 1]);
        BotUser::create(['chat_id' => 1002, 'platform' => 'telegram', 'topic_id' => 2, 'is_banned' => true]);
        BotUser::create(['chat_id' => 1003, 'platform' => 'vk', 'topic_id' => 3]);

        (new BroadcastMessageService())->handle($this->update([
            'sticker' => ['file_id' => 'STICKER_FILE_ID', 'file_unique_id' => 'u', 'type' => 'regular', 'width' => 512, 'height' => 512, 'is_animated' => false, 'is_video' => false],
        ], 5001));

        $jobs = $this->batchedJobs();

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(SendBroadcastMessageJob::class, $jobs[0]);
        $this->assertSame('sendSticker', $jobs[0]->queryParams->methodQuery);
        $this->assertSame('STICKER_FILE_ID', $jobs[0]->queryParams->sticker);
        $this->assertSame($active->id, $jobs[0]->botUserId);
    }

    public function test_text_and_photo_are_still_broadcast(): void
    {
        Bus::fake();
        Cache::flush();

        BotUser::create(['chat_id' => 1001, 'platform' => 'telegram', 'topic_id' => 1]);

        (new BroadcastMessageService())->handle($this->update(['text' => 'Всем привет'], 5002));
        (new BroadcastMessageService())->handle($this->update([
            'photo' => [['file_id' => 'SMALL', 'file_unique_id' => 'a', 'width' => 10, 'height' => 10], ['file_id' => 'BIG', 'file_unique_id' => 'b', 'width' => 100, 'height' => 100]],
        ], 5003));

        $methods = array_map(fn ($job) => $job->queryParams->methodQuery, $this->batchedJobs());

        $this->assertEqualsCanonicalizing(['sendMessage', 'sendPhoto'], $methods);
    }
}
