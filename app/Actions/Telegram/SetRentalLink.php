<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Services\Rental\RentalLinks;
use Illuminate\Support\Facades\Log;

/**
 * Ротация ссылок меню администратором из группы:
 * /set_link <название> <https://новая-ссылка>, например /set_link график https://…
 */
class SetRentalLink
{
    public const COMMAND = '/set_link';

    /**
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update): void
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

            [$name, $url] = $this->parse($update->text);
            $key = $name !== '' ? RentalLinks::resolve($name) : null;

            if ($key === null) {
                $this->reply($groupId, $update->messageThreadId, __('messages.set_link.usage', ['names' => RentalLinks::namesHint()]));
                return;
            }

            if (RentalLinks::isOffWord($url)) {
                RentalLinks::disable($key);
                Log::info('SetRentalLink: пункт отключён', ['key' => $key, 'set_by_user_id' => $update->fromUserId]);
                $this->reply($groupId, $update->messageThreadId, __('messages.set_link.disabled', ['title' => RentalLinks::title($key)]));
                return;
            }

            if (!RentalLinks::isValidUrl($url)) {
                $this->reply($groupId, $update->messageThreadId, __('messages.command_set_org_link_invalid'));
                return;
            }

            if (RentalLinks::get($key) === $url) {
                $this->reply($groupId, $update->messageThreadId, __('messages.set_link.unchanged', ['title' => RentalLinks::title($key)]));
                return;
            }

            RentalLinks::set($key, $url);

            Log::info('SetRentalLink: ссылка заменена', ['key' => $key, 'set_by_user_id' => $update->fromUserId]);

            $this->reply($groupId, $update->messageThreadId, __('messages.set_link.saved', ['title' => RentalLinks::title($key)]));
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('SetRentalLink: неожиданная ошибка', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param string|null $text
     *
     * @return array{0: string, 1: string} [название, ссылка]
     */
    private function parse(?string $text): array
    {
        $rest = trim(preg_replace('/^' . preg_quote(self::COMMAND, '/') . '(?:@\w+)?\s*/i', '', (string)$text));

        if ($rest === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/u', $rest, 2);

        return [$parts[0], trim($parts[1] ?? '')];
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
