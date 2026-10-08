<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\AdminPanel;
use App\DTOs\TelegramUpdateDto;
use App\Enums\SafeCodeType;
use App\Models\SafeCode;
use App\Services\Rental\RentalLinks;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Rental\OfferDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\TestCase;

/**
 * Панель администратора: кнопки вместо ввода команд
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1001234567890;

    private string $chatMemberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => [
                'message_id' => random_int(1000, 999999),
                'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $request['text'] ?? '',
            ]]);
        });
    }

    private function message(array $extra = []): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => array_merge([
                'message_id' => random_int(1, 1000),
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_ID, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => '/panel',
            ], $extra),
        ]));
    }

    private function callbackDto(string $data): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'callback_query' => [
                'id' => (string)random_int(1000, 999999),
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin'],
                'message' => [
                    'message_id' => 50,
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
                    'chat' => ['id' => self::GROUP_ID, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                    'date' => time(),
                    'text' => 'panel',
                ],
                'data' => $data,
            ],
        ]));
    }

    /** reply_markup как строка JSON: в теле запроса он может прийти и массивом, и строкой */
    private function markup(Request $request): string
    {
        $markup = $request['reply_markup'] ?? '';

        return is_array($markup) ? json_encode($markup, JSON_UNESCAPED_UNICODE) : (string)$markup;
    }

    private function recorded(string $method): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method
        ));
    }

    private function lastBotText(): ?string
    {
        $texts = array_map(fn ($pair) => $pair[0]['text'] ?? null, $this->recorded('sendMessage'));

        return end($texts) ?: null;
    }

    /** Нажимает кнопку «с вводом» и возвращает id сообщения-подсказки */
    private function pressPromptButton(string $action): int
    {
        (new AdminPanel())->handleCallback($this->callbackDto("panel:{$action}"));

        $prompts = array_filter($this->recorded('sendMessage'), fn ($pair) => str_contains($this->markup($pair[0]), 'force_reply'));
        $this->assertNotEmpty($prompts, 'бот не прислал подсказку для ввода');

        return (int)end($prompts)[1]->json('result.message_id');
    }

    public function test_admin_gets_a_pinned_panel_with_buttons(): void
    {
        (new AdminPanel())->send($this->message());

        $sent = $this->recorded('sendMessage');
        $this->assertCount(1, $sent);
        $keyboard = $this->markup($sent[0][0]);

        foreach (['show_offer', 'set_offer', 'rotate', 'backup_status', 'backup_now', 'backup_on', 'backup_off', 'backup_time'] as $action) {
            $this->assertStringContainsString("panel:{$action}", $keyboard);
        }

        $this->assertCount(1, $this->recorded('pinChatMessage'));
    }

    public function test_new_panel_replaces_the_previously_pinned_one(): void
    {
        (new AdminPanel())->send($this->message());
        $firstId = (int)$this->recorded('sendMessage')[0][1]->json('result.message_id');

        (new AdminPanel())->send($this->message());

        $unpins = $this->recorded('unpinChatMessage');
        $this->assertCount(1, $unpins);
        $this->assertSame($firstId, (int)$unpins[0][0]['message_id']);
    }

    public function test_non_admin_cannot_open_the_panel(): void
    {
        $this->chatMemberStatus = 'member';

        (new AdminPanel())->send($this->message());

        $this->assertCount(0, $this->recorded('pinChatMessage'));
        $this->assertStringContainsString('администратор', $this->lastBotText());
    }

    public function test_button_without_parameters_runs_the_command_immediately(): void
    {
        $this->assertFalse(app(DatabaseBackupService::class)->isEnabled());

        (new AdminPanel())->handleCallback($this->callbackDto('panel:backup_on'));

        $this->assertTrue(app(DatabaseBackupService::class)->isEnabled());
        $this->assertStringContainsString('включён', $this->lastBotText());
        $this->assertCount(1, $this->recorded('answerCallbackQuery'));
    }

    public function test_non_admin_pressing_a_button_gets_an_alert_and_nothing_happens(): void
    {
        $this->chatMemberStatus = 'member';

        (new AdminPanel())->handleCallback($this->callbackDto('panel:backup_on'));

        $this->assertFalse(app(DatabaseBackupService::class)->isEnabled());
        $alerts = $this->recorded('answerCallbackQuery');
        $this->assertTrue((bool)$alerts[0][0]['show_alert']);
    }

    public function test_rotate_menu_lists_every_code_and_link_with_their_state(): void
    {
        RentalLinks::set('schedule', 'https://example.com/schedule');
        SafeCode::create(['code' => '1111', 'type' => SafeCodeType::SAFE->value]);

        (new AdminPanel())->handleCallback($this->callbackDto('panel:rotate'));

        $menu = array_values(array_filter($this->recorded('sendMessage'), fn ($pair) => str_contains($this->markup($pair[0]), 'panel:link_schedule')));
        $this->assertCount(1, $menu);

        $keyboard = $this->markup($menu[0][0]);
        foreach (['set_code', 'set_building_code', 'set_org_link', 'link_hub', 'link_cabinets', 'link_map', 'link_schedule', 'link_payment', 'link_wifi', 'link_keys'] as $action) {
            $this->assertStringContainsString("panel:{$action}", $keyboard);
        }

        // Задано — галочка, не задано — прочерк
        $flat = json_decode($keyboard, true)['inline_keyboard'];
        $labels = array_column(array_merge(...$flat), 'text', 'callback_data');
        $this->assertStringContainsString('✅', $labels['panel:set_code']);
        $this->assertStringContainsString('➖', $labels['panel:set_building_code']);
        $this->assertStringContainsString('✅', $labels['panel:link_schedule']);
        $this->assertStringContainsString('➖', $labels['panel:link_keys']);
    }

    public function test_link_button_asks_for_a_url_and_the_reply_rotates_it(): void
    {
        $promptId = $this->pressPromptButton('link_schedule');

        $reply = $this->message(['text' => 'https://new.example.com/sheet', 'reply_to_message' => [
            'message_id' => $promptId, 'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time(), 'text' => 'p',
        ]]);

        (new AdminPanel())->handleReply($reply);

        $this->assertSame('https://new.example.com/sheet', RentalLinks::get('schedule'));
    }

    public function test_code_button_asks_for_a_value_and_the_reply_sets_it(): void
    {
        $promptId = $this->pressPromptButton('set_code');

        $reply = $this->message(['text' => '4321', 'reply_to_message' => [
            'message_id' => $promptId,
            'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'],
            'date' => time(),
            'text' => 'prompt',
        ]]);

        $this->assertTrue(AdminPanel::isPendingReply($reply));

        (new AdminPanel())->handleReply($reply);

        $this->assertSame('4321', SafeCode::current(SafeCodeType::SAFE)?->code);

        // Подсказка одноразовая: повторный ответ уже не обрабатывается
        $this->assertFalse(AdminPanel::isPendingReply($reply));
    }

    public function test_org_link_and_backup_time_replies_are_validated_by_the_usual_handlers(): void
    {
        $linkPrompt = $this->pressPromptButton('set_org_link');
        (new AdminPanel())->handleReply($this->message(['text' => 'не ссылка', 'reply_to_message' => [
            'message_id' => $linkPrompt, 'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time(), 'text' => 'p',
        ]]));
        $this->assertNull(SafeCode::current(SafeCodeType::ORG_LINK));
        $this->assertStringContainsString('http', $this->lastBotText());

        $timePrompt = $this->pressPromptButton('backup_time');
        (new AdminPanel())->handleReply($this->message(['text' => '04:15', 'reply_to_message' => [
            'message_id' => $timePrompt, 'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time(), 'text' => 'p',
        ]]));
        $this->assertSame('04:15', app(DatabaseBackupService::class)->time());
    }

    public function test_offer_button_accepts_a_pdf_sent_in_reply(): void
    {
        $promptId = $this->pressPromptButton('set_offer');

        (new AdminPanel())->handleReply($this->message([
            'text' => null,
            'document' => ['file_id' => 'PANEL_PDF', 'file_name' => 'Оферта.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1000],
            'reply_to_message' => [
                'message_id' => $promptId, 'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
                'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time(), 'text' => 'p',
            ],
        ]));

        $this->assertSame('PANEL_PDF', OfferDocument::fileId());
    }

    public function test_reply_to_an_unrelated_message_is_not_treated_as_a_panel_reply(): void
    {
        $reply = $this->message(['text' => '1234', 'reply_to_message' => [
            'message_id' => 999999, 'from' => ['id' => 5, 'is_bot' => false, 'first_name' => 'X'],
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'], 'date' => time(), 'text' => 'hi',
        ]]);

        $this->assertFalse(AdminPanel::isPendingReply($reply));
        $this->assertTrue(AdminPanel::isPanelCallback('panel:backup_on'));
        $this->assertFalse(AdminPanel::isPanelCallback('topic_user_ban_true'));
    }
}
