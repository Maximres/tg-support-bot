<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Models\SafeCode;

/**
 * Отправка актуального значения (код сейфа/здания, ссылка на орг. информацию)
 * доверенному пользователю по команде /code, /building_code, /org_link
 *
 * В отличие от кнопок в закреплённом сообщении, значение отправляется обычным
 * сообщением (остаётся в переписке) — это упрощённый текстовый путь получения
 * того же значения, симметричный набору команд администратора /set_*
 */
class SendSafeCode
{
    /**
     * @param BotUser      $botUser
     * @param SafeCodeType $type
     *
     * @return void
     */
    public function execute(BotUser $botUser, SafeCodeType $type = SafeCodeType::SAFE): void
    {
        if ($type->requiresTrust() && !$botUser->isTrusted()) {
            $this->send($botUser, __('messages.access_not_trusted'));
            return;
        }

        $current = SafeCode::current($type);

        if ($type->isUrl()) {
            if (!$current) {
                $this->send($botUser, __('messages.access_org_link_not_set'));
                return;
            }

            $this->send($botUser, __('messages.access_org_link_message'), [
                [
                    ['text' => __('messages.but_access_open_org_link'), 'url' => $current->code],
                ],
            ]);
            return;
        }

        $text = $current
            ? __('messages.command_code_value', ['type' => $type->label(), 'code' => htmlspecialchars($current->code, ENT_QUOTES)])
            : __('messages.command_code_not_set', ['type' => $type->label()]);

        $this->send($botUser, $text);
    }

    /**
     * @param BotUser    $botUser
     * @param string     $text
     * @param array|null $inlineKeyboard
     *
     * @return void
     */
    private function send(BotUser $botUser, string $text, ?array $inlineKeyboard = null): void
    {
        $params = [
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => $text,
            'parse_mode' => 'html',
        ];

        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from($params));
    }
}
