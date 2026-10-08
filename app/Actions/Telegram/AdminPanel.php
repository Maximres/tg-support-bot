<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotSetting;
use App\Models\SafeCode;
use App\Services\Rental\RentalLinks;
use App\TelegramBot\TelegramMethods;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Панель администратора: сообщение с кнопками (закрепляется в группе), чтобы не вводить команды руками.
 *
 * Действия без параметров выполняются сразу, для остальных бот просит ответить на его сообщение
 * нужным значением (код, ссылка, время, PDF). Все проверки прав и логика — в тех же обработчиках,
 * что и у текстовых команд, поэтому кнопки и команды ведут себя одинаково.
 */
class AdminPanel
{
    use AnswersCallbackQuery;

    public const COMMAND = '/panel';

    public const ROTATE_CALLBACK = 'panel:rotate';

    private const CALLBACK_PREFIX = 'panel:';

    private const PROMPT_TTL_SECONDS = 900;

    private const KEY_PANEL_MESSAGE_ID = 'panel.message_id';

    /** Кнопка => команда, которая выполняется сразу */
    private const DIRECT = [
        'show_offer' => '/show_offer',
        'backup_status' => '/backup_status',
        'backup_now' => '/backup_now',
        'backup_on' => '/backup_on',
        'backup_off' => '/backup_off',
    ];

    /** Кнопка => [команда, подсказка], для которых нужно значение от администратора */
    private const PROMPTS = [
        'set_offer' => ['/set_offer', 'messages.panel.prompt_set_offer'],
        'set_code' => ['/set_code', 'messages.panel.prompt_set_code'],
        'set_building_code' => ['/set_building_code', 'messages.panel.prompt_set_building_code'],
        'set_org_link' => ['/set_org_link', 'messages.panel.prompt_set_org_link'],
        'backup_time' => ['/backup_time', 'messages.panel.prompt_backup_time'],
    ];

    /**
     * @param string|null $callbackData
     *
     * @return bool
     */
    public static function isPanelCallback(?string $callbackData): bool
    {
        return $callbackData !== null && str_starts_with($callbackData, self::CALLBACK_PREFIX);
    }

    /**
     * Ответ на сообщение-подсказку панели (значение, которое ждёт бот)
     *
     * @param TelegramUpdateDto $update
     *
     * @return bool
     */
    public static function isPendingReply(TelegramUpdateDto $update): bool
    {
        $promptId = $update->replyToMessage['message_id'] ?? null;

        return $promptId !== null && Cache::has(self::promptKey($update->chatId, (int)$promptId));
    }

    /**
     * Команда /panel: отправить панель и закрепить её
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function send(TelegramUpdateDto $update): void
    {
        $groupId = config('traffic_source.settings.telegram.group_id');

        try {
            $isAdmin = VerifyGroupAdmin::check((int)$groupId, $update->fromUserId);
            if ($isAdmin !== true) {
                $this->reply($groupId, $update->messageThreadId, $isAdmin === null
                    ? __('messages.command_admin_check_failed')
                    : __('messages.command_admin_only'));
                return;
            }

            $response = TelegramMethods::sendQueryTelegram('sendMessage', array_filter([
                'chat_id' => $groupId,
                'message_thread_id' => $update->messageThreadId,
                'text' => __('messages.panel.text'),
                'parse_mode' => 'html',
                'reply_markup' => ['inline_keyboard' => $this->keyboard()],
            ], fn ($value) => $value !== null));

            if (!$response->ok || empty($response->message_id)) {
                Log::warning('AdminPanel: не удалось отправить панель', ['error' => $response->rawData ?? null]);
                return;
            }

            // Снимаем закрепление прежней панели, чтобы в группе была одна актуальная
            $previousId = BotSetting::get(self::KEY_PANEL_MESSAGE_ID);
            if (!empty($previousId)) {
                TelegramMethods::sendQueryTelegram('unpinChatMessage', ['chat_id' => $groupId, 'message_id' => (int)$previousId]);
            }

            TelegramMethods::sendQueryTelegram('pinChatMessage', [
                'chat_id' => $groupId,
                'message_id' => $response->message_id,
                'disable_notification' => true,
            ]);

            BotSetting::set(self::KEY_PANEL_MESSAGE_ID, (string)$response->message_id);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('AdminPanel: ошибка при отправке панели', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Нажатие кнопки панели
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function handleCallback(TelegramUpdateDto $update): void
    {
        $groupId = config('traffic_source.settings.telegram.group_id');

        try {
            $isAdmin = VerifyGroupAdmin::check((int)$groupId, $update->fromUserId);
            if ($isAdmin !== true) {
                $this->ack($update->callbackId, $isAdmin === null
                    ? __('messages.command_admin_check_failed')
                    : __('messages.command_admin_only'), true);
                return;
            }

            $action = substr((string)$update->callbackData, strlen(self::CALLBACK_PREFIX));

            if ($action === 'rotate') {
                $this->ack($update->callbackId);
                $this->sendRotateMenu($update);
                return;
            }

            if (str_starts_with($action, 'link_') && in_array(substr($action, 5), RentalLinks::KEYS, true)) {
                $key = substr($action, 5);
                $this->ack($update->callbackId);
                $this->askForValue(
                    $update,
                    SetRentalLink::COMMAND . ' ' . $key,
                    __('messages.panel.prompt_link', ['title' => RentalLinks::title($key)])
                );
                return;
            }

            if (isset(self::DIRECT[$action])) {
                $this->ack($update->callbackId);
                $this->runCommand($this->syntheticMessage($update, self::DIRECT[$action]), self::DIRECT[$action]);
                return;
            }

            if (isset(self::PROMPTS[$action])) {
                $this->ack($update->callbackId);
                $this->askForValue($update, self::PROMPTS[$action][0], __(self::PROMPTS[$action][1]));
                return;
            }

            $this->ack($update->callbackId);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('AdminPanel: ошибка при обработке кнопки', [
                'data' => $update->callbackData,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Значение, присланное в ответ на подсказку панели
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function handleReply(TelegramUpdateDto $update): void
    {
        try {
            $key = self::promptKey($update->chatId, (int)($update->replyToMessage['message_id'] ?? 0));
            $command = Cache::pull($key);

            if (empty($command)) {
                return;
            }

            if ($command === '/set_offer') {
                // PDF уже приложен к этому сообщению — обработчик сам найдёт его
                $this->runCommand($update, $command);
                return;
            }

            $value = trim((string)$update->text);
            $this->runCommand($this->withText($update, $command . ' ' . $value), $command);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('AdminPanel: ошибка при обработке ответа', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Выполнить команду теми же обработчиками, что и при вводе текстом
     *
     * @param TelegramUpdateDto $update
     * @param string            $command
     *
     * @return void
     */
    private function runCommand(TelegramUpdateDto $update, string $command): void
    {
        if (str_starts_with($command, SetRentalLink::COMMAND)) {
            (new SetRentalLink())->execute($update);
            return;
        }

        match ($command) {
            '/set_code' => (new SetTrustedValue())->execute($update, SafeCodeType::SAFE),
            '/set_building_code' => (new SetTrustedValue())->execute($update, SafeCodeType::BUILDING),
            '/set_org_link' => (new SetTrustedValue())->execute($update, SafeCodeType::ORG_LINK),
            '/set_offer', '/show_offer' => (new HandleOfferDocumentCommand())->execute($update, $command),
            default => (new HandleBackupCommand())->execute($update, $command),
        };
    }

    /**
     * @param TelegramUpdateDto $update
     * @param string            $command
     * @param string            $hint
     *
     * @return void
     */
    private function askForValue(TelegramUpdateDto $update, string $command, string $hint): void
    {
        $response = TelegramMethods::sendQueryTelegram('sendMessage', array_filter([
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $update->messageThreadId,
            'text' => $hint,
            'parse_mode' => 'html',
            'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => __('messages.panel.placeholder')],
        ], fn ($value) => $value !== null));

        if ($response->ok && !empty($response->message_id)) {
            Cache::put(self::promptKey($update->chatId, (int)$response->message_id), $command, self::PROMPT_TTL_SECONDS);
        }
    }

    /**
     * Сообщение «от нажавшего кнопку» с текстом команды — чтобы переиспользовать текстовые обработчики
     *
     * @param TelegramUpdateDto $update
     * @param string            $text
     *
     * @return TelegramUpdateDto
     */
    private function syntheticMessage(TelegramUpdateDto $update, string $text): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(Request::create('api/telegram/bot', 'POST', [
            'update_id' => $update->updateId,
            'message' => array_filter([
                'message_id' => $update->messageId ?? 0,
                'message_thread_id' => $update->messageThreadId,
                'from' => ['id' => $update->fromUserId, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat' => ['id' => $update->chatId, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $text,
            ], fn ($value) => $value !== null),
        ]));
    }

    /**
     * @param TelegramUpdateDto $update
     * @param string            $text
     *
     * @return TelegramUpdateDto
     */
    private function withText(TelegramUpdateDto $update, string $text): TelegramUpdateDto
    {
        $copy = clone $update;
        $copy->text = $text;

        return $copy;
    }

    /**
     * @return array
     */
    private function keyboard(): array
    {
        $button = fn (string $action) => ['text' => __("messages.panel.but_{$action}"), 'callback_data' => self::CALLBACK_PREFIX . $action];

        return [
            [$button('show_offer'), $button('set_offer')],
            [$button('rotate')],
            [$button('backup_status'), $button('backup_now')],
            [$button('backup_on'), $button('backup_off'), $button('backup_time')],
        ];
    }

    /**
     * Меню ротации: все коды и ссылки, значения которых админ может заменить (✅ задано, ➖ не задано)
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    private function sendRotateMenu(TelegramUpdateDto $update): void
    {
        $mark = fn (bool $isSet) => $isSet ? ' ✅' : ' ➖';
        $buttons = [];

        foreach ([['set_code', SafeCodeType::SAFE], ['set_building_code', SafeCodeType::BUILDING], ['set_org_link', SafeCodeType::ORG_LINK]] as [$action, $type]) {
            $buttons[] = [
                'text' => __("messages.panel.but_{$action}") . $mark(SafeCode::current($type) !== null),
                'callback_data' => self::CALLBACK_PREFIX . $action,
            ];
        }

        foreach (RentalLinks::KEYS as $key) {
            $buttons[] = [
                'text' => __("messages.panel.but_link_{$key}") . $mark(RentalLinks::get($key) !== null),
                'callback_data' => self::CALLBACK_PREFIX . 'link_' . $key,
            ];
        }

        TelegramMethods::sendQueryTelegram('sendMessage', array_filter([
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $update->messageThreadId,
            'text' => __('messages.panel.rotate_text'),
            'parse_mode' => 'html',
            'reply_markup' => ['inline_keyboard' => array_chunk($buttons, 2)],
        ], fn ($value) => $value !== null));
    }

    /**
     * @param int|string|null $chatId
     * @param int             $messageId
     *
     * @return string
     */
    private static function promptKey(int|string|null $chatId, int $messageId): string
    {
        return "admin_panel_prompt:{$chatId}:{$messageId}";
    }

    /**
     * @param int|string $groupId
     * @param int|null   $threadId
     * @param string     $text
     *
     * @return void
     */
    private function reply(int|string $groupId, ?int $threadId, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $groupId,
            'message_thread_id' => $threadId,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }
}
