<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\Broadcast;
use App\Services\Broadcast\BroadcastManager;
use App\Services\Broadcast\BroadcastSettings;
use Illuminate\Support\Facades\Log;

/**
 * Управление массовой рассылкой администраторами:
 *  /broadcast_delete — ответом на сообщение рассылки (или «last» — последняя): удалить рассылку у клиентов;
 *  /broadcast_off, /broadcast_on — выключить/включить рассылку (на время работ с ботом);
 *  /broadcast_status — состояние и последняя рассылка.
 */
class HandleBroadcastCommand
{
    public const COMMANDS = [
        '/broadcast_delete',
        '/broadcast_off',
        '/broadcast_on',
        '/broadcast_status',
    ];

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

            $text = match ($command) {
                '/broadcast_off' => $this->switchTo(false),
                '/broadcast_on' => $this->switchTo(true),
                '/broadcast_status' => $this->status(),
                '/broadcast_delete' => $this->delete($update),
                default => null,
            };

            if ($text !== null) {
                $this->reply($groupId, $update->messageThreadId, $text);
            }
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('HandleBroadcastCommand: неожиданная ошибка', ['command' => $command, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param bool $enabled
     *
     * @return string
     */
    private function switchTo(bool $enabled): string
    {
        BroadcastSettings::setEnabled($enabled);

        return __($enabled ? 'messages.broadcast.on' : 'messages.broadcast.off');
    }

    /**
     * @return string
     */
    private function status(): string
    {
        $lines = [__(BroadcastSettings::enabled() ? 'messages.broadcast.status_on' : 'messages.broadcast.status_off')];

        $last = Broadcast::latest('id')->first();

        $lines[] = $last
            ? __('messages.broadcast.status_last', [
                'at' => $last->created_at->setTimezone(config('backup.timezone'))->format('d.m.Y H:i'),
                'clients' => $last->messages()->count(),
                'state' => $last->status === Broadcast::STATUS_DELETED ? __('messages.broadcast.state_deleted') : __('messages.broadcast.state_sent'),
            ])
            : __('messages.broadcast.status_none');

        return implode("\n", $lines);
    }

    /**
     * @param TelegramUpdateDto $update
     *
     * @return string
     */
    private function delete(TelegramUpdateDto $update): string
    {
        $argument = mb_strtolower(trim((string)preg_replace('/^\/broadcast_delete(?:@\w+)?\s*/i', '', (string)$update->text)));
        $replyId = (int)($update->replyToMessage['message_id'] ?? 0);

        if ($replyId > 0) {
            $broadcast = Broadcast::findBySource((int)$update->chatId, $replyId);
        } elseif ($argument === 'last') {
            $broadcast = Broadcast::where('status', '!=', Broadcast::STATUS_DELETED)->latest('id')->first();
        } else {
            return __('messages.broadcast.delete_usage');
        }

        if (!$broadcast) {
            return __('messages.broadcast.delete_not_found');
        }

        return (new BroadcastManager())->delete($broadcast);
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
