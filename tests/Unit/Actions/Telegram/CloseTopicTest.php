<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\CloseTopic;
use App\Jobs\SendMessage\SendVkSimpleMessageJob;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloseTopicTest extends TestCase
{
    use RefreshDatabase;

    private int $groupId;

    public function setUp(): void
    {
        parent::setUp();

        $this->groupId = -1001234567890;
        config(['traffic_source.settings.telegram.group_id' => $this->groupId]);

        Queue::fake();
    }

    public static function platforms(): array
    {
        return [['telegram'], ['vk'], ['test']];
    }

    #[DataProvider('platforms')]
    public function test_close_topic_sets_icon_and_closes_without_telling_the_client(string $platform): void
    {
        $botUser = BotUser::create(['chat_id' => random_int(1, 2_000_000_000), 'platform' => $platform, 'topic_id' => 4242]);

        (new CloseTopic())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $methods = array_map(fn ($job) => $job['job']->queryParams->methodQuery, $pushed);

        // Только служебные действия в группе: значок и закрытие темы, клиенту сообщений нет
        $this->assertSame(['editForumTopic', 'closeForumTopic'], $methods);
        foreach ($pushed as $job) {
            $this->assertEquals($this->groupId, $job['job']->queryParams->chat_id);
            $this->assertEquals(4242, $job['job']->queryParams->message_thread_id);
        }

        /** @phpstan-ignore-next-line */
        $this->assertEmpty(Queue::pushedJobs()[SendVkSimpleMessageJob::class] ?? []);

        $this->assertNotNull($botUser->fresh()->topic_closed_at);
    }

    public function test_close_topic_without_topic_does_nothing(): void
    {
        $botUser = BotUser::create(['chat_id' => random_int(1, 2_000_000_000), 'platform' => 'telegram']);

        (new CloseTopic())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $this->assertEmpty(Queue::pushedJobs());
    }
}
