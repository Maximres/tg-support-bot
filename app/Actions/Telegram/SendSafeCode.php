<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Models\SafeCode;

/**
 * Отправка актуального кода сейфа доверенному пользователю по команде /code
 */
class SendSafeCode
{
    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        if (!$botUser->isTrusted()) {
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $botUser->chat_id,
                'text' => __('messages.safe_code_not_trusted'),
                'parse_mode' => 'html',
            ]));
            return;
        }

        $safeCode = SafeCode::current();

        $text = $safeCode
            ? __('messages.safe_code_value', ['code' => htmlspecialchars($safeCode->code, ENT_QUOTES)])
            : __('messages.safe_code_not_set');

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }
}
