<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Services\Rental\PricesImage;
use Illuminate\Support\Facades\Log;

/**
 * Картинка кнопки «Цены» из рабочей группы:
 *  /set_prices_image — администратор присылает картинку с подписью /set_prices_image (или отвечает командой на сообщение
 *                      с картинкой); «/set_prices_image off» убирает картинку
 */
class HandlePricesImageCommand
{
    public const COMMANDS = ['/set_prices_image'];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

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

            $argument = mb_strtolower(trim((string)preg_replace(
                '/^' . preg_quote($command, '/') . '(?:@\w+)?\s*/i',
                '',
                (string)($update->text ?: $update->caption)
            )));

            if (in_array($argument, ['off', 'выкл', 'убрать', 'удалить'], true)) {
                PricesImage::clear();
                $this->reply($groupId, $update->messageThreadId, __('messages.prices_image.removed'));
                return;
            }

            $this->set($groupId, $update);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('HandlePricesImageCommand: неожиданная ошибка', ['command' => $command, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param int|string        $groupId
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    private function set(int|string $groupId, TelegramUpdateDto $update): void
    {
        // Картинка либо в самом сообщении (с подписью-командой), либо команда — ответ на сообщение с картинкой
        $message = $update->rawData['message'] ?? [];
        $source = !empty($message['photo']) || !empty($message['document']) ? $message : ($message['reply_to_message'] ?? []);

        $fileId = null;
        $type = PricesImage::TYPE_PHOTO;
        $size = 0;

        if (!empty($source['photo']) && is_array($source['photo'])) {
            // Telegram присылает несколько размеров одной картинки: берём самый большой
            $largest = end($source['photo']);
            $fileId = $largest['file_id'] ?? null;
            $size = (int)($largest['file_size'] ?? 0);
        } elseif (!empty($source['document']['file_id']) && str_starts_with((string)($source['document']['mime_type'] ?? ''), 'image/')) {
            $fileId = $source['document']['file_id'];
            $type = PricesImage::TYPE_DOCUMENT;
            $size = (int)($source['document']['file_size'] ?? 0);
        }

        if (empty($fileId)) {
            $this->reply($groupId, $update->messageThreadId, __('messages.prices_image.usage'));
            return;
        }

        if ($size > self::MAX_SIZE_BYTES) {
            $this->reply($groupId, $update->messageThreadId, __('messages.prices_image.too_big'));
            return;
        }

        PricesImage::set($fileId, $type);

        Log::info('HandlePricesImageCommand: картинка «Цены» заменена', ['set_by_user_id' => $update->fromUserId]);

        // Админ сразу видит, что именно получат клиенты
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => PricesImage::method(),
            'chat_id' => $groupId,
            'message_thread_id' => $update->messageThreadId,
            PricesImage::fileParam() => $fileId,
            'caption' => __('messages.prices_image.saved'),
        ]));
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
