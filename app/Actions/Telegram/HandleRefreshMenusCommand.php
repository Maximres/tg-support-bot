<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use Illuminate\Support\Facades\Log;

/**
 * /refresh_menus — администратор обновляет закреплённое меню у всех клиентов (после смены кнопок, ссылок, Wi-Fi).
 */
class HandleRefreshMenusCommand
{
    public const COMMANDS = ['/refresh_menus'];

    /**
     * @param TelegramUpdateDto $update
     * @param string            $command
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, string $command): void
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

            $this->reply($groupId, $update->messageThreadId, __('messages.refresh_menus.running'));

            set_time_limit(600);
            $result = (new RefreshAllAccessMenus())->execute();

            $this->reply($groupId, $update->messageThreadId, $result === null
                ? __('messages.refresh_menus.already_running')
                : __('messages.refresh_menus.done', $result));
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('HandleRefreshMenusCommand: неожиданная ошибка', ['command' => $command, 'error' => $e->getMessage()]);
        }
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
