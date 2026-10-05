<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;

/**
 * Повторное открытие закрытого обращения (топика): автоматически — когда клиент
 * пишет в закрытое обращение, и вручную — командой /reopen в топике
 */
class ReopenTopic
{
    public const REOPENED = 'reopened';

    public const ALREADY_OPEN = 'already_open';

    public const FAILED = 'failed';

    /**
     * @param BotUser $botUser
     *
     * @return string одна из констант self::*
     */
    public function execute(BotUser $botUser): string
    {
        if (empty($botUser->topic_id)) {
            return self::FAILED;
        }

        $response = TelegramMethods::sendQueryTelegram('reopenForumTopic', [
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
        ]);

        if ($response->ok === true) {
            $botUser->markTopicOpen();

            return self::REOPENED;
        }

        // Тема уже открыта (например, её открыли вручную) — просто синхронизируем флаг
        if ($response->type_error === 'TOPIC_NOT_MODIFIED' || str_contains((string)($response->description ?? ''), 'TOPIC_NOT_MODIFIED')) {
            $botUser->markTopicOpen();

            return self::ALREADY_OPEN;
        }

        return self::FAILED;
    }

    /**
     * Команда /reopen в топике клиента
     *
     * @param TelegramUpdateDto $update
     * @param BotUser           $botUser
     *
     * @return void
     */
    public function handleCommand(TelegramUpdateDto $update, BotUser $botUser): void
    {
        $text = match ($this->execute($botUser)) {
            self::REOPENED => __('messages.topic_reopened'),
            self::ALREADY_OPEN => __('messages.topic_already_open'),
            default => __('messages.topic_reopen_failed'),
        };

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => config('traffic_source.settings.telegram.group_id'),
            'message_thread_id' => $update->messageThreadId ?? $botUser->topic_id,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }
}
