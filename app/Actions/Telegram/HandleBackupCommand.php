<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Services\Backup\DatabaseBackupService;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Управление бэкапом БД администраторами из служебной супергруппы:
 * /backup_on, /backup_off, /backup_time ЧЧ:ММ, /backup_days N, /backup_to ID, /backup_now, /backup_status
 */
class HandleBackupCommand
{
    public const COMMANDS = [
        '/backup_on',
        '/backup_off',
        '/backup_time',
        '/backup_days',
        '/backup_to',
        '/backup_now',
        '/backup_status',
    ];

    public function __construct(private ?DatabaseBackupService $backup = null)
    {
        $this->backup ??= app(DatabaseBackupService::class);
    }

    /**
     * @param TelegramUpdateDto $update
     * @param string            $command одна из self::COMMANDS
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
                '/backup_on' => $this->enable(),
                '/backup_off' => $this->disable(),
                '/backup_time' => $this->setTime($update->text, $command),
                '/backup_days' => $this->setDays($update->text, $command),
                '/backup_to' => $this->setRecipient($update->text, $command, $update->fromUserId),
                '/backup_status' => $this->status(),
                '/backup_now' => $this->runNow($groupId, $update->messageThreadId),
                default => null,
            };

            if ($text !== null) {
                $this->reply($groupId, $update->messageThreadId, $text);
            }
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('HandleBackupCommand: неожиданная ошибка', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return string
     */
    private function enable(): string
    {
        $this->backup->setEnabled(true);

        $text = __('messages.backup.on', $this->timeParams());

        if ($problem = $this->backup->configurationProblem()) {
            $text .= "\n\n" . $problem;
        }

        return $text;
    }

    /**
     * @return string
     */
    private function disable(): string
    {
        $this->backup->setEnabled(false);

        return __('messages.backup.off');
    }

    /**
     * @param string|null $text
     * @param string      $command
     *
     * @return string
     */
    private function setTime(?string $text, string $command): string
    {
        $time = trim(preg_replace('/^' . preg_quote($command, '/') . '(?:@\w+)?\s*/i', '', (string)$text));

        if (!DatabaseBackupService::isValidTime($time)) {
            return __('messages.backup.time_invalid');
        }

        $this->backup->setTime($time);

        return __('messages.backup.time_set', $this->timeParams());
    }

    /**
     * @param string|null $text
     * @param string      $command
     *
     * @return string
     */
    private function setDays(?string $text, string $command): string
    {
        $days = $this->argument($text, $command);

        if (!DatabaseBackupService::isValidDays($days)) {
            return __('messages.backup.days_invalid', ['max' => DatabaseBackupService::MAX_DAYS]);
        }

        $this->backup->setDays((int)$days);

        return __('messages.backup.days_set', [
            'schedule' => $this->schedule((int)$days),
            'time' => $this->backup->time(),
            'tz' => config('backup.timezone'),
        ]);
    }

    /**
     * Сменить получателя копий. Принимает id пользователя/группы или слово «я» (тот, кто пишет команду).
     * Перед сохранением отправляет получателю проверочное сообщение: если доставить нельзя
     * (получатель ещё не запускал бота), прежний получатель остаётся.
     *
     * @param string|null $text
     * @param string      $command
     * @param int|null    $fromUserId
     *
     * @return string
     */
    private function setRecipient(?string $text, string $command, ?int $fromUserId): string
    {
        $argument = mb_strtolower($this->argument($text, $command));

        if (in_array($argument, ['я', 'me'], true)) {
            if (empty($fromUserId) || $fromUserId === TelegramUpdateDto::ANONYMOUS_ADMIN_ID) {
                return __('messages.backup.recipient_anonymous');
            }

            $argument = (string)$fromUserId;
        }

        if (!DatabaseBackupService::isValidChatId($argument)) {
            return __('messages.backup.recipient_invalid');
        }

        $response = TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $argument,
            'text' => __('messages.backup.recipient_test'),
        ]);

        if (!$response->ok) {
            return __('messages.backup.recipient_unreachable', [
                'id' => $argument,
                'error' => (string)($response->rawData['description'] ?? 'unknown'),
            ]);
        }

        $this->backup->setChatId($argument);

        return __('messages.backup.recipient_set', ['id' => $argument]);
    }

    /**
     * @param string|null $text
     * @param string      $command
     *
     * @return string
     */
    private function argument(?string $text, string $command): string
    {
        return trim(preg_replace('/^' . preg_quote($command, '/') . '(?:@\w+)?\s*/i', '', (string)$text));
    }

    /**
     * @param int $days
     *
     * @return string
     */
    private function schedule(int $days): string
    {
        return $days === 1 ? __('messages.backup.schedule_daily') : __('messages.backup.schedule_every', ['days' => $days]);
    }

    /**
     * @return string
     */
    private function status(): string
    {
        $status = $this->backup->status();

        $lines = [
            __('messages.backup.status_state', [
                'state' => $status['enabled'] ? __('messages.backup.state_on') : __('messages.backup.state_off'),
                'schedule' => $this->schedule($status['days']),
                'time' => $status['time'],
                'tz' => $status['timezone'],
            ]),
            __('messages.backup.status_recipient', ['id' => $status['chat_id'] ?? '—']),
        ];

        if ($status['last_at'] === null) {
            $lines[] = __('messages.backup.status_never');
        } elseif ($status['last_ok']) {
            $lines[] = __('messages.backup.status_last_ok', [
                'at' => $status['last_at'],
                'size' => intdiv((int)$status['last_size'], 1024),
            ]);
        } else {
            $lines[] = __('messages.backup.status_last_failed', [
                'at' => $status['last_at'],
                'error' => $status['last_error'],
            ]);
        }

        $lines[] = __('messages.backup.status_local', ['count' => $status['local_copies']]);

        if ($problem = $this->backup->configurationProblem()) {
            $lines[] = $problem;
        }

        return implode("\n", $lines);
    }

    /**
     * @param int|string $groupId
     * @param int|null   $threadId
     *
     * @return string
     */
    private function runNow(int|string $groupId, ?int $threadId): string
    {
        if ($problem = $this->backup->configurationProblem()) {
            return $problem;
        }

        $this->reply($groupId, $threadId, __('messages.backup.running'));

        set_time_limit(300);
        $result = $this->backup->run();

        return $result['ok']
            ? __('messages.backup.done', ['size' => intdiv($result['size'], 1024)])
            : __('messages.backup.failed', ['error' => $result['error']]);
    }

    /**
     * @return array
     */
    private function timeParams(): array
    {
        return [
            'schedule' => $this->schedule($this->backup->days()),
            'time' => $this->backup->time(),
            'tz' => config('backup.timezone'),
        ];
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
