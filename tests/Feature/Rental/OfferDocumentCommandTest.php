<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\HandleOfferDocumentCommand;
use App\Actions\Telegram\SendOfferMessage;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Замена PDF договора-оферты из рабочей группы (/set_offer) и просмотр (/show_offer)
 */
class OfferDocumentCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();

        config(['rental.offer_document' => 'ENV_FILE_ID']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => [
                'message_id' => random_int(1000, 999999),
                'chat' => ['id' => $request['chat_id'] ?? 0, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $request['text'] ?? '',
            ]]);
        });
    }

    private function groupDto(array $message): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => array_merge([
                'message_id' => time(),
                'message_thread_id' => 55,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => -1001234567890, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
            ], $message),
        ]));
    }

    private function pdf(string $fileId = 'NEW_FILE_ID', string $name = 'Оферта 2027.pdf'): array
    {
        return ['file_id' => $fileId, 'file_name' => $name, 'mime_type' => 'application/pdf', 'file_size' => 150000];
    }

    private function lastReply(): ?string
    {
        $texts = [];
        foreach (Http::recorded()->all() as [$request]) {
            if (str_contains($request->url(), 'sendMessage')) {
                $texts[] = $request['text'];
            }
        }

        return end($texts) ?: null;
    }

    public function test_falls_back_to_env_value_until_replaced(): void
    {
        $this->assertSame('ENV_FILE_ID', OfferDocument::fileId());
    }

    public function test_admin_replaces_offer_by_attaching_pdf_with_command_caption(): void
    {
        (new HandleOfferDocumentCommand())->execute(
            $this->groupDto(['caption' => '/set_offer', 'document' => $this->pdf()]),
            '/set_offer'
        );

        $this->assertSame('NEW_FILE_ID', OfferDocument::fileId());
        $this->assertSame('Оферта 2027.pdf', OfferDocument::fileName());
        $this->assertStringContainsString('Договор-оферта обновлён', $this->lastReply());
    }

    public function test_admin_replaces_offer_by_replying_to_a_message_with_pdf(): void
    {
        (new HandleOfferDocumentCommand())->execute($this->groupDto([
            'text' => '/set_offer',
            'reply_to_message' => ['message_id' => 1, 'from' => ['id' => 5, 'is_bot' => false, 'first_name' => 'X'], 'chat' => ['id' => -1001234567890, 'type' => 'supergroup'], 'date' => time(), 'document' => $this->pdf('REPLIED_ID')],
        ]), '/set_offer');

        $this->assertSame('REPLIED_ID', OfferDocument::fileId());
    }

    public function test_new_clients_receive_the_replaced_offer(): void
    {
        (new HandleOfferDocumentCommand())->execute(
            $this->groupDto(['caption' => '/set_offer', 'document' => $this->pdf('FRESH_ID')]),
            '/set_offer'
        );

        $botUser = BotUser::create(['chat_id' => 123456, 'platform' => 'telegram', 'topic_id' => 1]);
        (new SendOfferMessage())->execute($botUser);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendDocument') && $r['document'] === 'FRESH_ID');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendDocument') && $r['document'] === 'ENV_FILE_ID');
    }

    public function test_non_admin_cannot_replace_offer(): void
    {
        $this->chatMemberStatus = 'member';

        (new HandleOfferDocumentCommand())->execute(
            $this->groupDto(['caption' => '/set_offer', 'document' => $this->pdf()]),
            '/set_offer'
        );

        $this->assertSame('ENV_FILE_ID', OfferDocument::fileId());
        $this->assertStringContainsString('администратор', $this->lastReply());
    }

    public function test_rejects_non_pdf_files(): void
    {
        (new HandleOfferDocumentCommand())->execute(
            $this->groupDto(['caption' => '/set_offer', 'document' => ['file_id' => 'X', 'file_name' => 'photo.png', 'mime_type' => 'image/png', 'file_size' => 1000]]),
            '/set_offer'
        );

        $this->assertSame('ENV_FILE_ID', OfferDocument::fileId());
        $this->assertStringContainsString('PDF', $this->lastReply());
    }

    public function test_rejects_oversized_files(): void
    {
        $document = $this->pdf();
        $document['file_size'] = 25 * 1024 * 1024;

        (new HandleOfferDocumentCommand())->execute($this->groupDto(['caption' => '/set_offer', 'document' => $document]), '/set_offer');

        $this->assertSame('ENV_FILE_ID', OfferDocument::fileId());
        $this->assertStringContainsString('слишком большой', $this->lastReply());
    }

    public function test_command_without_a_file_explains_how_to_use_it(): void
    {
        (new HandleOfferDocumentCommand())->execute($this->groupDto(['text' => '/set_offer']), '/set_offer');

        $this->assertSame('ENV_FILE_ID', OfferDocument::fileId());
        $this->assertStringContainsString('/set_offer', $this->lastReply());
    }

    public function test_show_offer_sends_the_active_document_to_the_topic(): void
    {
        (new HandleOfferDocumentCommand())->execute($this->groupDto(['text' => '/show_offer']), '/show_offer');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendDocument')
            && $r['document'] === 'ENV_FILE_ID'
            && (int)$r['message_thread_id'] === 55);
    }
}
