<?php

namespace Tests\Feature\Jobs;

use App\Actions\Telegram\UpdateContactMessage;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Контактная карточка в теме: с фото профиля клиента, если оно есть
 */
class ContactCardPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private bool $hasPhoto = true;

    private bool $photoSendFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['traffic_source.settings.telegram.group_id' => self::GROUP_ID]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getUserProfilePhotos')) {
                return Http::response(['ok' => true, 'result' => [
                    'total_count' => $this->hasPhoto ? 1 : 0,
                    'photos' => $this->hasPhoto ? [[['file_id' => 'SMALL', 'width' => 160], ['file_id' => 'LARGE', 'width' => 640]]] : [],
                ]]);
            }

            if (str_contains($request->url(), 'sendPhoto') && $this->photoSendFails) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: wrong file identifier'], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 880, 'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time()]]);
        });
    }

    private function client(array $overrides = []): BotUser
    {
        return BotUser::create(array_merge([
            'chat_id' => 5550001,
            'platform' => 'telegram',
            'topic_id' => 4242,
            'full_name' => 'Иванов Иван Иванович',
            'phone_number' => '+375291234567',
            'email' => 'ivanov@example.com',
        ], $overrides));
    }

    private function sent(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    public function test_new_card_is_a_photo_with_the_card_text_as_caption(): void
    {
        $botUser = $this->client();

        (new UpdateContactMessage())->execute($botUser);

        $photos = $this->sent('sendPhoto');
        $this->assertCount(1, $photos);
        $this->assertSame('LARGE', $photos[0][0]['photo']);
        $this->assertStringContainsString('КОНТАКТНАЯ ИНФОРМАЦИЯ', $photos[0][0]['caption']);
        $this->assertStringContainsString('Иванов Иван Иванович', $photos[0][0]['caption']);

        $botUser->refresh();
        $this->assertTrue($botUser->contact_info_is_photo);
        $this->assertSame(880, (int)$botUser->contact_info_message_id);
        $this->assertCount(1, $this->sent('pinChatMessage'));
    }

    public function test_card_without_a_profile_photo_stays_a_text_message(): void
    {
        $this->hasPhoto = false;
        $botUser = $this->client();

        (new UpdateContactMessage())->execute($botUser);

        $this->assertCount(0, $this->sent('sendPhoto'));
        $this->assertStringContainsString('КОНТАКТНАЯ ИНФОРМАЦИЯ', $this->sent('sendMessage')[0][0]['text']);
        $this->assertFalse($botUser->fresh()->contact_info_is_photo);
    }

    public function test_photo_card_is_edited_through_its_caption(): void
    {
        $botUser = $this->client(['contact_info_message_id' => 700, 'contact_info_is_photo' => true]);

        (new UpdateContactMessage())->execute($botUser);

        $edits = $this->sent('editMessageCaption');
        $this->assertCount(1, $edits);
        $this->assertSame(700, (int)$edits[0][0]['message_id']);
        $this->assertArrayHasKey('caption', $edits[0][0]->data());
        $this->assertCount(0, $this->sent('editMessageText'));
        $this->assertCount(0, $this->sent('sendPhoto'));
    }

    public function test_text_card_is_still_edited_as_text(): void
    {
        $botUser = $this->client(['contact_info_message_id' => 701, 'contact_info_is_photo' => false]);

        (new UpdateContactMessage())->execute($botUser);

        $this->assertCount(1, $this->sent('editMessageText'));
        $this->assertCount(0, $this->sent('editMessageCaption'));
    }

    public function test_if_the_photo_cannot_be_sent_the_card_goes_out_as_text(): void
    {
        $this->photoSendFails = true;
        $botUser = $this->client();

        (new UpdateContactMessage())->execute($botUser);

        $this->assertCount(1, $this->sent('sendPhoto'));
        $messages = $this->sent('sendMessage');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('КОНТАКТНАЯ ИНФОРМАЦИЯ', $messages[0][0]['text']);
        $this->assertArrayNotHasKey('caption', $messages[0][0]->data());

        $botUser->refresh();
        $this->assertFalse($botUser->contact_info_is_photo);
        $this->assertSame(880, (int)$botUser->contact_info_message_id);
    }
}
