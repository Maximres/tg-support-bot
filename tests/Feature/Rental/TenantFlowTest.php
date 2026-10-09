<?php

namespace Tests\Feature\Rental;

use App\Actions\Telegram\AcceptOffer;
use App\Actions\Telegram\CloseTopic;
use App\Actions\Telegram\HandleRegistrationFlow;
use App\Actions\Telegram\SendStartMessage;
use App\Actions\Telegram\ShowRentalMaterial;
use App\Actions\Telegram\TrustContactMessage;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use Tests\Mocks\Tg\TelegramUpdate_SafeCodeButtonMock;
use Tests\TestCase;

/**
 * Сквозной сценарий арендатора по действиям бота (без HTTP-роутинга: контроллер завершает
 * запрос через die(), что нельзя выполнять внутри тестового процесса):
 * /start -> регистрация -> оферта -> согласие -> меню -> доверие -> ключи -> закрытие -> новое сообщение
 */
class TenantFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 4242424;

    private const TOPIC_ID = 777;

    private int $messageCounter = 100;

    /** Пока тема "закрыта", отправка в неё сообщений даёт TOPIC_CLOSED */
    private bool $topicIsClosed = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rental.offer_document' => 'https://example.com/offer.pdf',
            'rental.emergency_phone' => '+375291071837',
            'rental.links' => [
                'hub' => 'https://example.com/hub',
                'cabinets' => 'https://example.com/cabinets',
                'map' => 'https://example.com/map',
                'schedule' => 'https://example.com/schedule',
                'payment' => 'https://example.com/payment',
                'wifi' => 'https://example.com/wifi',
                'keys' => 'https://example.com/keys',
            ],
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'reopenForumTopic')) {
                $this->topicIsClosed = false;

                return Http::response(['ok' => true, 'result' => true]);
            }

            if (str_contains($url, 'sendMessage') && $this->topicIsClosed && !empty($request['message_thread_id'])) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: TOPIC_CLOSED'], 400);
            }

            $result = [
                'message_id' => random_int(1000, 999999),
                'chat' => ['id' => $request['chat_id'] ?? 0, 'type' => 'private'],
                'date' => time(),
                'text' => $request['text'] ?? '',
            ];

            if (str_contains($url, 'createForumTopic')) {
                $result['message_thread_id'] = self::TOPIC_ID;
            }

            return Http::response(['ok' => true, 'result' => $result]);
        });
    }

    private function privateDto(string $text): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => ++$this->messageCounter,
            'message' => [
                'message_id' => ++$this->messageCounter,
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Test', 'last_name' => 'Tenant'],
                'chat' => ['id' => self::CHAT_ID, 'first_name' => 'Test', 'last_name' => 'Tenant', 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ]));
    }

    private function callbackDto(string $data): TelegramUpdateDto
    {
        return TelegramUpdate_SafeCodeButtonMock::getDto(
            TelegramUpdate_SafeCodeButtonMock::getDtoParams(self::CHAT_ID, $data)
        );
    }

    private function sentCount(string $method, ?callable $filter = null): int
    {
        return count(array_filter(
            Http::recorded()->all(),
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) === $method && ($filter === null || $filter($pair[0]))
        ));
    }

    private function markup(Request $request): string
    {
        $markup = $request['reply_markup'] ?? '';

        return is_array($markup) ? json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$markup;
    }

    /** Клавиатура последнего отправленного или отредактированного меню клиента */
    private function menuKeyboardJson(): string
    {
        foreach (array_reverse(Http::recorded()->all()) as [$request]) {
            $isMenuCall = str_contains($request->url(), 'sendMessage') || str_contains($request->url(), 'editMessageText');

            if ($isMenuCall && str_contains($this->markup($request), 'offer_show')) {
                return $this->markup($request);
            }
        }

        return '';
    }

    private function register(): BotUser
    {
        (new SendStartMessage())->execute($this->privateDto('/start'));

        $botUser = BotUser::where('chat_id', self::CHAT_ID)->firstOrFail();
        $flow = new HandleRegistrationFlow();

        // На /start — одно сообщение: приветствие и первый вопрос (ФИО), без дубля
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'ФИО')));
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'Добрый день')
            && str_contains($r['text'] ?? '', 'Напишите пожалуйста свои ФИО полностью')));

        // Повторный /start посреди регистрации повторяет текущий вопрос, а не показывает общее приветствие
        (new SendStartMessage())->execute($this->privateDto('/start'));
        $this->assertSame(2, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'ФИО')));
        $this->assertSame(0, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'Чем я могу вам помочь')));

        // «123» — не ФИО: регистрация не двигается дальше, клиент получает подсказку
        $this->assertTrue($flow->execute($this->privateDto('123'), $botUser->fresh()));
        $this->assertEmpty($botUser->fresh()->full_name);
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'укажите ФИО полностью')));

        $this->assertTrue($flow->execute($this->privateDto('Иванов Иван Иванович'), $botUser->fresh()));
        $this->assertTrue($flow->execute($this->privateDto('+375291234567'), $botUser->fresh()));
        $this->assertTrue($flow->execute($this->privateDto('ivanov@example.com'), $botUser->fresh()));

        return $botUser->fresh();
    }

    public function test_new_tenant_goes_through_the_whole_flow(): void
    {
        // 1-4. /start, регистрация (ФИО, телефон, email) -> создаётся топик
        $botUser = $this->register();

        $this->assertTrue($botUser->isRegistrationCompleted());
        $this->assertSame(self::TOPIC_ID, (int)$botUser->topic_id);

        // 4a. Сначала подтверждение регистрации, и только потом оферта
        $order = array_map(
            fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)) . '|' . ($pair[0]['text'] ?? $pair[0]['caption'] ?? ''),
            Http::recorded()->all()
        );
        $completedAt = array_key_first(array_filter($order, fn ($row) => str_contains($row, 'Регистрация завершена')));
        $offerAt = array_key_first(array_filter($order, fn ($row) => str_starts_with($row, 'sendDocument|')));
        $this->assertNotNull($completedAt, 'нет сообщения о завершении регистрации');
        $this->assertNotNull($offerAt, 'нет оферты');
        $this->assertLessThan($offerAt, $completedAt, 'оферта пришла раньше подтверждения регистрации');

        // 5. Вместо меню приходит оферта; меню и закрепа пока нет
        $this->assertSame(1, $this->sentCount('sendDocument', fn ($r) => str_contains($r['reply_markup'] ?? '', 'offer_accept')));
        $this->assertSame(0, $this->sentCount('pinChatMessage'));
        $this->assertFalse($botUser->hasAcceptedOffer());

        // 6. Карточка в группе показывает, что оферта не принята
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'Оферта не принята')));

        // 7. Клиент принимает оферту
        (new AcceptOffer())->execute($this->callbackDto('offer_accept'), $botUser);
        $botUser->refresh();

        $this->assertTrue($botUser->hasAcceptedOffer());
        $this->assertNotNull($botUser->access_message_id);
        $this->assertSame(1, $this->sentCount('pinChatMessage'));
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'Договор-оферта принят') && str_contains($r['text'] ?? '', '+375291071837')));
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => (int)($r['chat_id'] ?? 0) === self::CHAT_ID && !str_contains($r['text'] ?? '', 'Регистрация') && !str_contains($r['text'] ?? '', 'ФИО') && !str_contains($r['text'] ?? '', 'телефон') && !str_contains($r['text'] ?? '', 'email') && !str_contains($r['text'] ?? '', 'эмейл')));

        // 8. Карточка обновилась
        $this->assertSame(1, $this->sentCount('editMessageText', fn ($r) => str_contains($r['text'] ?? '', 'Оферта принята')));

        // 9. Пока доступ не открыт, в меню только открытые всем пункты: ни графика, ни ключей, ни кодов
        $menu = $this->menuKeyboardJson();
        $this->assertStringContainsString('offer_show', $menu);
        $this->assertStringContainsString('link_show_cabinets', $menu);
        foreach (['link_show_hub', 'link_show_schedule', 'link_show_wifi', 'link_show_keys'] as $closed) {
            $this->assertStringNotContainsString($closed, $menu);
        }
        $this->assertStringNotContainsString('access_show_', $menu);
        $this->assertStringNotContainsString('example.com', $menu);

        // 10. Ссылка до выдачи доступа не отдаётся
        $linksSent = fn () => $this->sentCount('sendMessage', fn ($r) => str_contains($this->markup($r), 'example.com'));
        $before = $linksSent();
        (new ShowRentalMaterial())->showLink($this->callbackDto('link_show_keys'), $botUser, 'keys');
        $this->assertSame($before, $linksSent());

        // 10a. Админ открывает доступ: меню у клиента правится на месте и появляется уведомление
        (new TrustContactMessage())->execute($botUser, true);

        $this->assertSame(1, $this->sentCount('editMessageText', fn ($r) => str_contains($this->markup($r), 'link_show_hub')));
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => str_contains($r['text'] ?? '', 'открыт доступ')));
        $menu = $this->menuKeyboardJson();
        $this->assertStringContainsString('link_show_keys', $menu);
        $this->assertStringNotContainsString('example.com', $menu);

        // 10b. Теперь ссылка отдаётся
        (new ShowRentalMaterial())->showLink($this->callbackDto('link_show_keys'), $botUser->fresh(), 'keys');
        $this->assertSame($before + 1, $linksSent());

        // 10c. Отзыв доступа: меню снова сворачивается, ссылка не отдаётся
        (new TrustContactMessage())->execute($botUser->fresh(), false);
        $this->assertStringNotContainsString('link_show_keys', $this->menuKeyboardJson());

        $before = $linksSent();
        (new ShowRentalMaterial())->showLink($this->callbackDto('link_show_keys'), $botUser->fresh(), 'keys');
        $this->assertSame($before, $linksSent());

        (new TrustContactMessage())->execute($botUser->fresh(), true);

        // 11. Обращение закрывают, клиент пишет снова -> тема открывается раньше сообщения
        (new CloseTopic())->execute($botUser->fresh());
        $this->assertTrue($botUser->fresh()->isTopicClosed());
        $this->topicIsClosed = true;

        $reopenBefore = $this->sentCount('reopenForumTopic');
        (new SendTelegramMessageJob(
            $botUser->id,
            $this->privateDto('Добрый день, есть вопрос'),
            TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'text' => 'Добрый день, есть вопрос',
            ]),
            'incoming'
        ))->handle();

        $this->assertSame($reopenBefore + 1, $this->sentCount('reopenForumTopic'));
        $this->assertFalse($botUser->fresh()->isTopicClosed());
        $this->assertSame(1, $this->sentCount('sendMessage', fn ($r) => ($r['text'] ?? '') === 'Добрый день, есть вопрос' && (int)$r['message_thread_id'] === self::TOPIC_ID));
    }

    public function test_existing_tenant_with_old_menu_gets_offer_and_updated_menu(): void
    {
        // Сотрудник зарегистрирован до появления оферты: данные есть, старое меню закреплено
        $botUser = BotUser::create([
            'chat_id' => self::CHAT_ID,
            'platform' => 'telegram',
            'topic_id' => self::TOPIC_ID,
            'full_name' => 'Булгач Максим Юрьевич',
            'phone_number' => '+375292120585',
            'email' => 'test@test.com',
            'registration_completed_at' => now(),
            'access_message_id' => 356,
        ]);

        // /restore_access для непринявшего оферту показывает оферту, а не старое меню
        (new \App\Actions\Telegram\SendOfferMessage())->execute($botUser);
        $this->assertSame(1, $this->sentCount('sendDocument'));
        $this->assertSame(0, $this->sentCount('pinChatMessage'));

        // Согласие -> старое меню откреплено, новое отправлено и закреплено
        (new AcceptOffer())->execute($this->callbackDto('offer_accept'), $botUser);
        $botUser->refresh();

        $this->assertTrue($botUser->hasAcceptedOffer());
        $this->assertNotSame(356, (int)$botUser->access_message_id);
        $this->assertSame(1, $this->sentCount('unpinChatMessage', fn ($r) => (int)$r['message_id'] === 356));
        $this->assertSame(1, $this->sentCount('pinChatMessage'));

        // Старое сообщение удалено, а новое меню без доступа содержит только договор
        $this->assertSame(1, $this->sentCount('deleteMessage', fn ($r) => (int)$r['message_id'] === 356));
        $menu = $this->menuKeyboardJson();
        $this->assertStringContainsString('offer_show', $menu);
        $this->assertStringNotContainsString('link_show_keys', $menu);
    }
}
