<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Models\SafeCode;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Обработка нажатия кнопки "Показать код"/"Орг. информация" сотрудником
 */
class ShowTrustedValue
{
    /**
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     * @param SafeCodeType|null $type   null — нераспознанный callback_data (например, устаревшая клавиатура)
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, BotUser $botUser, ?SafeCodeType $type): void
    {
        if (!$type) {
            // Нераспознанный тип — всё равно гасим "спиннер" на кнопке, ничего не показываем
            $this->ack($update->callbackId, '', false);
            return;
        }

        if ($botUser->isBanned() || ($type->requiresTrust() && !$botUser->isTrusted())) {
            $this->ack($update->callbackId, __('messages.access_not_trusted'), true);
            return;
        }

        if ($type->isUrl()) {
            $this->revealOrgLink($update, $botUser, $type);
            return;
        }

        $current = SafeCode::current($type);
        $text = $current
            ? __('messages.access_value_reveal', ['type' => $type->label(), 'value' => $current->code])
            : __('messages.access_value_not_set', ['type' => $type->label()]);

        $this->ack($update->callbackId, $text, true);
    }

    /**
     * Свежая url-кнопка с текущей ссылкой — генерируется на каждое нажатие,
     * поэтому закреплённая кнопка никогда не устаревает при смене ссылки админом
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     * @param SafeCodeType      $type
     *
     * @return void
     */
    private function revealOrgLink(TelegramUpdateDto $update, BotUser $botUser, SafeCodeType $type): void
    {
        $current = SafeCode::current($type);

        if (!$current) {
            $this->ack($update->callbackId, __('messages.access_org_link_not_set'), true);
            return;
        }

        $this->ack($update->callbackId, '', false);

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.access_org_link_message'),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('messages.but_access_open_org_link'), 'url' => $current->code],
                    ],
                ],
            ],
        ]));
    }

    /**
     * Подтверждает нажатие кнопки (гасит "спиннер") и, при необходимости,
     * показывает всплывающее окно, видимое только нажавшему
     *
     * @param int|null $callbackId
     * @param string   $text
     * @param bool     $showAlert
     *
     * @return void
     */
    private function ack(?int $callbackId, string $text, bool $showAlert): void
    {
        if (empty($callbackId)) {
            return;
        }

        $response = TelegramMethods::sendQueryTelegram('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($text, 0, 200),
            'show_alert' => $showAlert,
        ]);

        if (!$response->ok) {
            Log::warning('ShowTrustedValue: не удалось подтвердить callback_query', [
                'callback_id' => $callbackId,
                'error' => $response->rawData['description'] ?? null,
            ]);
        }
    }
}
